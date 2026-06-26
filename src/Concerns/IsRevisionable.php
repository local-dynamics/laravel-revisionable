<?php

namespace LocalDynamics\Revisionable\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use LocalDynamics\Revisionable\FieldModifier;
use LocalDynamics\Revisionable\Models\Revision;

trait IsRevisionable
{
    protected array $dirtyData = [];

    protected bool $revisionEnabled = true;

    private array $originalData = [];

    private array $updatedData = [];

    private array $lastRevisionAttributes = [];

    private bool $updating = false;

    private array $dontKeep = [];

    private array $doKeep = [];

    public static function bootIsRevisionable(): void
    {
        static::saving(function ($model) {
            $model->preSave();
        });

        static::updated(function ($model) {
            $model->postUpdate();
        });

        static::created(function ($model) {
            $model->postCreate();
        });

        static::deleted(function ($model) {
            $model->preSave();
            $model->postDelete();
            $model->postForceDelete();
        });
    }

    public function preSave(): void
    {
        if (! $this->revisionableEnabled()) {
            return;
        }

        $this->originalData = $this->original;
        $this->updatedData = $this->attributes;

        // we can only safely compare basic items,
        // so for now we drop any object based items, like DateTime.
        // JSON-cast attributes are compared canonically later, in
        // changedRevisionableFields(), so no normalisation is needed here.
        foreach ($this->updatedData as $key => $val) {
            if (is_object($val) && ! method_exists($val, '__toString')) {
                unset($this->originalData[$key]);
                unset($this->updatedData[$key]);
                $this->dontKeep[] = $key;
            }
        }

        // the below is ugly, for sure, but it's required so we can save the standard model
        // then use the keep / dontkeep values for later, in the isRevisionable method
        $this->dontKeep = isset($this->dontKeepRevisionOf)
            ? array_merge($this->dontKeepRevisionOf, $this->dontKeep)
            : $this->dontKeep;

        $this->doKeep = isset($this->keepRevisionOf)
            ? array_merge($this->keepRevisionOf, $this->doKeep)
            : $this->doKeep;

        // Eloquent's own timestamps are never meaningful revisions: updated_at
        // changes on every save, and created_at is tracked explicitly via
        // postCreate(). deleted_at is intentionally left trackable so soft
        // deletes are still recorded.
        if ($this->usesTimestamps()) {
            $this->dontKeep[] = $this->getCreatedAtColumn();
            $this->dontKeep[] = $this->getUpdatedAtColumn();
        }

        unset($this->attributes['dontKeepRevisionOf']);
        unset($this->attributes['keepRevisionOf']);

        $this->dirtyData = $this->getDirty();
        $this->updating = $this->exists;
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

        $hasLimit = property_exists($this, 'historyLimit');
        $cleanup = $this->revisionCleanup ?? false;

        // Without cleanup, the history limit simply stops further tracking
        // once it has been reached.
        if ($hasLimit && ! $cleanup && $this->revisionHistory()->count() >= $this->historyLimit) {
            return;
        }

        $this->insertRevisions($this->changedRevisionableFields(), 'saved');

        // With cleanup, keep only the newest $historyLimit revisions and
        // prune everything older in a single query.
        if ($hasLimit && $cleanup) {
            $keepIds = $this->revisionHistory()
                ->orderByDesc('id')
                ->limit($this->historyLimit)
                ->pluck('id');

            $this->revisionHistory()->whereKeyNot($keepIds)->delete();
        }

        $this->originalData = [];
        $this->updatedData = [];
        $this->dirtyData = [];
    }

    public function revisionHistory(): MorphMany
    {
        return $this->morphMany(Revision::class, 'revisionable');
    }

    /**
     * Get all the changes that have been made, that are also supposed
     * to have their changes recorded
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedRevisionableFields(): array
    {
        $relevantChanges = [];
        foreach ($this->dirtyData as $key => $newValue) {
            if ($this->isRevisionable($key) && ! is_array($newValue)) {
                $oldRaw = array_key_exists($key, $this->lastRevisionAttributes)
                    ? Arr::get($this->lastRevisionAttributes, $key)
                    : Arr::get($this->originalData, $key);

                $oldValue = FieldModifier::convertValue($oldRaw);

                if (! array_key_exists($key, $this->originalData) || $this->revisionValueChanged($key, $oldRaw, $newValue)) {
                    $relevantChanges[] = [
                        'key' => $key,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                    ];
                    $this->lastRevisionAttributes[$key] = $newValue;
                }
            } else {
                unset($this->updatedData[$key]);
                unset($this->originalData[$key]);
            }
        }

        return $relevantChanges;
    }

    /**
     * Check if this field should have a revision kept
     */
    private function isRevisionable(string $key): bool
    {
        // If the field is explicitly revisionable, then return true.
        // If it's explicitly not revisionable, return false.
        // Otherwise, if neither condition is met, only return true if
        // we aren't specifying revisionable fields.
        if (isset($this->doKeep) && in_array($key, $this->doKeep)) {
            return true;
        }
        if (isset($this->dontKeep) && in_array($key, $this->dontKeep)) {
            return false;
        }

        return empty($this->doKeep);
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
            'user_id' => auth()->id(),
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

    public function postDelete(): void
    {
        if (! $this->revisionableEnabled()) {
            return;
        }

        if (
            $this->isSoftDelete()
            && $this->isRevisionable($this->getDeletedAtColumn())
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
