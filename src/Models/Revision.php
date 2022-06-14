<?php

namespace LocalDynamics\Revisionable\Models;

use Exception;
use Illuminate\Database\Eloquent\Model as Eloquent;
use Illuminate\Support\Str;
use LocalDynamics\Revisionable\FieldFormatter;

/**
 * LocalDynamics\Revisionable\Models\Revision
 *
 * @property int $id
 * @property int $revisionable_type
 * @property int $revisionable_id
 * @property int $revision
 * @property string|null $process
 * @property string $key
 * @property string|null $old_value
 * @property string|null $new_value
 * @property int|null $user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Eloquent|\Eloquent $revisionable
 * @property-read \App\Models\User|null $user
 * @method static \Illuminate\Database\Eloquent\Builder|Revision newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Revision newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Revision query()
 * @mixin \Eloquent
 */
class Revision extends Eloquent
{
    public $table = 'revisions';
    protected $revisionFormattedFields = [];
    public const UPDATED_AT = null;

    public function revisionable() { return $this->morphTo(); }

    public function fieldName() : string
    {
        if ($formatted = $this->formatFieldName($this->key)) {
            return $formatted;
        }

        if (strpos($this->key, '_id')) {
            return str_replace('_id', '', $this->key);
        }

        return $this->key;
    }

    private function formatFieldName($key) : bool
    {
        $related_model = $this->getActualClassNameForMorph($this->revisionable_type);
        $related_model = new $related_model();
        $revisionFormattedFieldNames = $related_model->getRevisionFormattedFieldNames();

        if (isset($revisionFormattedFieldNames[$key])) {
            return $revisionFormattedFieldNames[$key];
        }

        return false;
    }

    public function oldValue() : string { return $this->getValue('old'); }

    private function getValue(string $which = 'new') : string
    {
        $which_value = $which.'_value';

        // First find the main model that was updated
        $main_model = $this->revisionable_type;
        // Load it, WITH the related model
        if (class_exists($main_model)) {
            $main_model = new $main_model();

            try {
                if ($this->isRelated()) {
                    $related_model = $this->getRelatedModel();

                    // Now we can find out the namespace of of related model
                    if (! method_exists($main_model, $related_model)) {
                        $related_model = Str::camel($related_model); // for cases like published_status_id
                        if (! method_exists($main_model, $related_model)) {
                            throw new Exception('Relation '.$related_model.' does not exist for '.get_class($main_model));
                        }
                    }
                    $related_class = $main_model->$related_model()->getRelated();

                    // Finally, now that we know the namespace of the related model
                    // we can load it, to find the information we so desire
                    $item = $related_class::find($this->$which_value);

                    if (is_null($this->$which_value) || $this->$which_value == '') {
                        $item = new $related_class();

                        return $item->getRevisionNullString();
                    }
                    if (! $item) {
                        $item = new $related_class();

                        return $this->format($this->key, $item->getRevisionUnknownString());
                    }

                    // Check if model use IsRevisionable
                    if (method_exists($item, 'identifiableName')) {
                        // see if there's an available mutator
                        $mutator = 'get'.studly_case($this->key).'Attribute';
                        if (method_exists($item, $mutator)) {
                            return $this->format($item->$mutator($this->key), $item->identifiableName());
                        }

                        return $this->format($this->key, $item->identifiableName());
                    }
                }
            } catch (Exception $e) {
                // Just a fail-safe, in the case the data setup isn't as expected
                // Nothing to do here.
            }

            // if there was an issue
            // or, if it's a normal value

            $mutator = 'get'.studly_case($this->key).'Attribute';
            if (method_exists($main_model, $mutator)) {
                return $this->format($this->key, $main_model->$mutator($this->$which_value));
            }
        }

        return $this->format($this->key, $this->$which_value);
    }

    private function isRelated() : bool
    {
        $isRelated = false;
        $idSuffix = '_id';
        $pos = strrpos($this->key, $idSuffix);

        if ($pos !== false
            && strlen($this->key) - strlen($idSuffix) === $pos
        ) {
            $isRelated = true;
        }

        return $isRelated;
    }

    private function getRelatedModel() : string
    {
        $idSuffix = '_id';

        return substr($this->key, 0, strlen($this->key) - strlen($idSuffix));
    }

    public function format($key, $value) : string
    {
        $related_model = $this->getActualClassNameForMorph($this->revisionable_type);
        $related_model = new $related_model();
        $revisionFormattedFields = $related_model->getRevisionFormattedFields();

        if (isset($revisionFormattedFields[$key])) {
            return FieldFormatter::format($key, $value, $revisionFormattedFields);
        } else {
            return $value;
        }
    }

    public function newValue() : string { return $this->getValue('new'); }

    public function user()
    {
        return $this->belongsTo(config('auth.model') ?? config('auth.providers.users.model'));
    }


    /*
     * Examples:
    array(
        'public' => 'boolean:Yes|No',
        'minimum'  => 'string:Min: %s'
    )
     */

    /**
     * Returns the object we have the history of
     *
     * @return Object|false
     */
    public function historyOf()
    {
        if (class_exists($class = $this->revisionable_type)) {
            return $class::find($this->revisionable_id);
        }

        return false;
    }
}
