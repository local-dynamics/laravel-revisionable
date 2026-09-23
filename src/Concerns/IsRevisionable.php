<?php

namespace LocalDynamics\Revisionable\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use LocalDynamics\Revisionable\FieldModifier;
use LocalDynamics\Revisionable\Models\Revision;

trait IsRevisionable
{
    protected bool $revisionEnabled = true;

    /**
     * Attribute values as they were last recorded in a revision, keyed by
     * field. Persists across a whole save chain so nested saves continue the
     * revision chain instead of comparing against stale database originals.
     */
    private array $lastRevisionAttributes = [];

    /**
     * Stack of per-save-cycle state frames. A stack (rather than plain
     * properties) keeps nested saves - e.g. an observer that saves from
     * within the updated event - from clobbering each other's state.
     *
     * @var array<int, array{original: array, dirty: array, dontKeep: array, doKeep: array}>
     */
    private array $revisionStack = [];

    public static function bootIsRevisionable(): void
    {
        static::saving(function ($model) {
            $model->preSave();
        });

        static::updated(function ($model) {
            $model->postUpdate();
        });

        // saved fires after every (non-aborted) save - including creates and
        // no-op saves where updated never fires - so it is the reliable place
        // to pop the frame pushed in saving.
        static::saved(function ($model) {
            $model->finishRevision();
        });

        static::created(function ($model) {
            $model->postCreate();
        });

        static::deleted(function ($model) {
            $model->postDelete($model->captureRevisionFrame());
            $model->postForceDelete();
        });
    }

    public function preSave(): void
    {
        if (! $this->revisionableEnabled()) {
            return;
        }

        $this->revisionStack[] = $this->captureRevisionFrame();
    }

    /**
     * Build the state needed to compute revisions for a single save cycle,
     * without mutating $this (beyond stripping pseudo-attributes that must not
     * be persisted). The result is pushed onto the revision stack.
     */
    public function captureRevisionFrame(): array
    {
        $original = $this->original;
        $dontKeep = [];

        // We can only safely compare basic items, so drop any object based
        // values, like a DateTime without __toString. JSON-cast attributes are
        // compared canonically later, in changedRevisionableFields(), so they
        // need no normalisation here.
        foreach ($this->attributes as $key => $val) {
            if (is_object($val) && ! method_exists($val, '__toString')) {
                unset($original[$key]);
                $dontKeep[] = $key;
            }
        }

        $dontKeep = array_merge($this->dontKeepRevisionOf ?? [], $dontKeep);
        $doKeep = $this->keepRevisionOf ?? [];

        // Eloquent's own timestamps are never meaningful revisions: updated_at
        // changes on every save, and created_at is tracked explicitly via
        // postCreate(). deleted_at is intentionally left trackable so soft
        // deletes are still recorded.
        if ($this->usesTimestamps()) {
            $dontKeep[] = $this->getCreatedAtColumn();
            $dontKeep[] = $this->getUpdatedAtColumn();
        }

        // dontKeepRevisionOf / keepRevisionOf may have been assigned as pseudo
        // attributes; make sure they never reach the database.
        unset($this->attributes['dontKeepRevisionOf'], $this->attributes['keepRevisionOf']);

        return [
            'original' => $original,
            'dirty' => $this->getDirty(),
            'dontKeep' => $dontKeep,
            'doKeep' => $doKeep,
        ];
    }

    /**
     * Discard the current save cycle's state frame. Called on saved, which
     * fires once per non-aborted save regardless of create/update/no-op.
     */
    public function finishRevision(): void
    {
        array_pop($this->revisionStack);
    }

    private function revisionableEnabled(): bool
    {
        return (bool) config('revisionable.enabled', true) && $this->revisionEnabled;
    }

    public function postUpdate(): void
    {
        if (! $this->revisionableEnabled()) {
            return;
        }

        if (empty($this->revisionStack)) {
            return;
        }
        $frame = end($this->revisionStack);

        $hasLimit = property_exists($this, 'historyLimit');
        $cleanup = $this->revisionCleanup ?? false;

        // Without cleanup, the history limit simply stops further tracking
        // once it has been reached.
        if ($hasLimit && ! $cleanup && $this->revisionHistory()->count() >= $this->historyLimit) {
            return;
        }

        $this->insertRevisions($this->changedRevisionableFields($frame), 'saved');

        // With cleanup, keep only the newest $historyLimit revisions and
        // prune everything older in a single query.
        if ($hasLimit && $cleanup) {
            $keepIds = $this->revisionHistory()
                ->orderByDesc('id')
                ->limit($this->historyLimit)
                ->pluck('id');

            $this->revisionHistory()->whereKeyNot($keepIds)->delete();
        }
    }

    public function revisionHistory(): MorphMany
    {
        return $this->morphMany(Revision::class, 'revisionable');
    }

    /**
     * The most recent revisions across every instance of this model.
     */
    public static function classRevisionHistory(int $limit = 100, string $order = 'desc'): Collection
    {
        return Revision::query()
            ->where('revisionable_type', (new static)->getMorphClass())
            ->orderBy('id', $order)
            ->limit($limit)
            ->get();
    }

    /**
     * Resolve the id of the user responsible for a revision. Override this in
     * your model to source it from somewhere other than the default guard.
     */
    public function getSystemUserId(): int|string|null
    {
        return auth()->id();
    }

    /**
     * Get all the changes that have been made, that are also supposed
     * to have their changes recorded
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedRevisionableFields(array $frame): array
    {
        $relevantChanges = [];
        foreach ($frame['dirty'] as $key => $newValue) {
            if (! $this->isRevisionable($key, $frame) || is_array($newValue)) {
                continue;
            }

            $oldRaw = array_key_exists($key, $this->lastRevisionAttributes)
                ? Arr::get($this->lastRevisionAttributes, $key)
                : Arr::get($frame['original'], $key);

            // A key missing from $original was never loaded or written, so there is nothing to compare
            // against — Eloquent reports it dirty whatever its value. Setting it to null is not a change,
            // though: recording it wrote a "null → null" revision for every such key on the first save
            // after a partial insert. Compared strictly, because loosely null == 0 == false == '' and a
            // first 0 or false is a real value worth keeping.
            $changed = array_key_exists($key, $frame['original']) || array_key_exists($key, $this->lastRevisionAttributes)
                ? $this->revisionValueChanged($key, $oldRaw, $newValue)
                : $newValue !== null;

            if ($changed) {
                $relevantChanges[] = [
                    'key' => $key,
                    'old_value' => FieldModifier::convertValue($oldRaw),
                    'new_value' => $newValue,
                ];
                $this->lastRevisionAttributes[$key] = $newValue;
            }
        }

        return $relevantChanges;
    }

    /**
     * Check if this field should have a revision kept.
     *
     * If the field is explicitly revisionable, return true. If it's explicitly
     * not revisionable, return false. Otherwise only return true if we aren't
     * specifying an explicit set of revisionable fields.
     */
    private function isRevisionable(string $key, array $frame): bool
    {
        if (in_array($key, $frame['doKeep'])) {
            return true;
        }
        if (in_array($key, $frame['dontKeep'])) {
            return false;
        }

        return empty($frame['doKeep']);
    }

    /**
     * Decide whether a field actually changed. For JSON-cast attributes the
     * database may re-order object keys on write, so compare them canonically
     * to avoid recording a revision for a mere key re-ordering.
     */
    private function revisionValueChanged(string $key, $oldValue, $newValue): bool
    {
        if ($this->isJsonCast($key)) {
            return FieldModifier::canonicalJson($oldValue) !== FieldModifier::canonicalJson($newValue);
        }

        return FieldModifier::convertValue($oldValue) != $newValue;
    }

    private function isJsonCast(string $key): bool
    {
        $casts = $this->getCasts();

        return isset($casts[$key])
            && in_array($casts[$key], ['array', 'json', 'object', 'collection'], true);
    }

    private function insertRevisions(array $revisions, string $event): void
    {
        if (! count($revisions)) {
            return;
        }

        $default = [
            'revisionable_type' => $this->getMorphClass(),
            'revisionable_id' => $this->getKey(),
            'revision' => now()->microsecond,
            'process' => PHP_PROCESS_UID,
            'key' => null,
            'old_value' => null,
            'new_value' => null,
            'user_id' => $this->getSystemUserId(),
            'created_at' => now(),
        ];

        foreach ($revisions as &$revision) {
            $revision = array_merge($default, $revision);
        }
        unset($revision);

        Revision::insert($revisions);

        Event::dispatch('revisionable.'.$event, ['model' => $this, 'revisions' => $revisions]);
    }

    public function postCreate(): void
    {
        if (! $this->revisionableEnabled()) {
            return;
        }

        if (! ($this->revisionCreationsEnabled ?? false)) {
            return;
        }

        if ((! isset($this->revisionEnabled) || $this->revisionEnabled)) {
            $revisions[] = [
                'key' => self::CREATED_AT,
                'old_value' => null,
                'new_value' => $this->{self::CREATED_AT},
            ];

            $this->insertRevisions($revisions, 'created');
        }
    }

    public function postDelete(array $frame): void
    {
        if (! $this->revisionableEnabled()) {
            return;
        }

        if (
            $this->isSoftDelete()
            && $this->isRevisionable($this->getDeletedAtColumn(), $frame)
        ) {
            $revisions[] = [
                'key' => $this->getDeletedAtColumn(),
                'old_value' => null,
                'new_value' => $this->{$this->getDeletedAtColumn()},
            ];

            $this->insertRevisions($revisions, 'deleted');
        }
    }

    /**
     * Check if soft deletes are currently enabled on this model
     */
    private function isSoftDelete(): bool
    {
        if (isset($this->forceDeleting)) {
            return ! $this->forceDeleting;
        }

        return false;
    }

    public function postForceDelete(): void
    {
        if (empty($this->revisionForceDeleteEnabled)) {
            return;
        }

        if (! $this->revisionableEnabled()) {
            return;
        }

        if (($this->isSoftDelete() && $this->isForceDeleting()) || ! $this->isSoftDelete()) {
            $revisions = [[
                'key' => self::CREATED_AT,
                'old_value' => $this->{self::CREATED_AT},
                'new_value' => null,
            ]];

            $this->insertRevisions($revisions, 'deleted');
        }
    }

    public function disableRevisionable(): void
    {
        $this->revisionEnabled = false;
    }

    /**
     * @return mixed
     */
    public function getRevisionFormattedFields()
    {
        return $this->revisionFormattedFields;
    }

    /**
     * @return mixed
     */
    public function getRevisionFormattedFieldNames()
    {
        return $this->revisionFormattedFieldNames;
    }

    /**
     * Identifiable Name
     * When displaying revision history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function identifiableName(): string
    {
        return (string) $this->getKey();
    }

    /**
     * Revision Unknown String
     * When displaying revision history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionNullString(): string
    {
        return isset($this->revisionNullString) ? $this->revisionNullString : 'nothing';
    }

    /**
     * No revision string
     * When displaying revision history, if the revisions value
     * cant be figured out, this is used instead.
     * It can be overridden.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionUnknownString(): string
    {
        return isset($this->revisionUnknownString) ? $this->revisionUnknownString : 'unknown';
    }

    /**
     * Disable one or more revisionable fields temporarily.
     *
     * dontKeepRevisionOf is resolved through Eloquent's __get/__set, so it
     * cannot be appended to in place ("indirect modification of overloaded
     * property"). Build the list in a local variable and assign it back.
     */
    public function disableRevisionField(array|string $field): void
    {
        if (! isset($this->dontKeepRevisionOf)) {
            $this->dontKeepRevisionOf = [];
        }

        $ignored = $this->dontKeepRevisionOf;
        foreach ((array) $field as $one_field) {
            $ignored[] = $one_field;
        }
        $this->dontKeepRevisionOf = $ignored;
    }
}
