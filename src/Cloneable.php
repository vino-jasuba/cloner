<?php

namespace Bkwld\Cloner;

// Deps
use App;
use Illuminate\Database\Eloquent\Model;

/**
 * Mixin accessor methods, callbacks, and the duplicate() helper into models.
 * @property string[] $cloneable_relations
 */
trait Cloneable
{
    /**
     * Return the list of attributes on this model that should be cloned.
     *
     * @return  array
     */
    public function getCloneExemptAttributes(): array
    {
        // Always make the id and timestamps exempt
        $defaults = [
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];

        // Include the model count columns in the exempt columns
        $count_columns = array_map(function ($count_column) {
            return $count_column . '_count';
            }, $this->withCount);

        $defaults = array_merge($defaults, $count_columns);

        // If none specified, just return the defaults, else, merge them
        if (! isset($this->clone_exempt_attributes)) {
            return $defaults;
        }

        return array_merge($defaults, $this->clone_exempt_attributes);
    }

    /**
     * Return a list of attributes that reference files that should be duplicated
     * when the model is cloned.
     *
     * @return  array
     */
    public function getCloneableFileAttributes(): array
    {
        if (! isset($this->cloneable_file_attributes)) {
            return [];
        }

        return $this->cloneable_file_attributes;
    }

    /**
     * Return the list of relations on this model that should be cloned.
     *
     * @return  array
     */
    public function getCloneableRelations(): array
    {
        if (! isset($this->cloneable_relations)) {
            return [];
        }

        return $this->cloneable_relations;
    }

    /**
     * Add a relation to cloneable_relations uniquely.
     *
     * @param  string $relation
     * @return void
     */
    public function addCloneableRelation($relation): void
    {
        $relations = $this->getCloneableRelations();

        if (in_array($relation, $relations)) {
            return;
        }

        $relations[] = $relation;

        $this->cloneable_relations = $relations;
    }

    /**
     * Clone the current model instance.
     *
     * @return Model The new, saved clone
     */
    public function duplicate(): Model
    {
        return App::make('cloner')->duplicate($this);
    }

    /**
     * Clone the current model instance to a specific Laravel database connection.
     *
     * @param  string $connection A Laravel database connection
     * @return Model The new, saved clone
     */
    public function duplicateTo(string $connection): Model
    {
        return App::make('cloner')->duplicateTo($this, $connection);
    }

    /**
     * A no-op callback that gets fired when a model is cloning but before it gets
     * committed to the database.
     *
     * @param  Model $src
     * @param  bool $child
     * @return void
     */
    public function onCloning($src, $child = null) {}

    /**
     * A no-op callback that gets fired when a model is cloned and saved to the
     * database.
     *
     * @param  Model $src
     * @return void
     */
    public function onCloned($src) {}
}
