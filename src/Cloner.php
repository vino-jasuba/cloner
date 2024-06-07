<?php

namespace Bkwld\Cloner;

// Deps
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;

/**
 * Core class that traverses a model's relationships and replicates model
 * attributes.
 */
class Cloner
{
    /**
     * @var AttachmentAdapter
     */
    private $attachment;

    /**
     * @var Events
     */
    private $events;

    /**
     * @var string
     */
    private $write_connection;

    /**
     * DI.
     *
     * @param AttachmentAdapter|null $attachment
     * @param \Illuminate\Contracts\Events\Dispatcher|null $events
     */
    public function __construct(
        AttachmentAdapter $attachment = null,
        Events $events = null
    ) {
        $this->attachment = $attachment;
        $this->events = $events;
    }

    /**
     * Clone a model instance and all of it's files and relations.
     *
     * @param Model $model
     * @param \Illuminate\Database\Eloquent\Relations\Relation|null $relation
     *
     * @return Model The new model instance
     */
    public function duplicate(Model $model, Relation $relation = null): Model
    {
        $clone = $this->cloneModel($model);

        $this->dispatchOnCloningEvent($clone, $relation, $model);

        if ($relation) {
            if (! is_a($relation, BelongsTo::class)) {
                $relation->save($clone);
            }
        } else {
            $clone->save();
        }

        $this->duplicateAttachments($model, $clone);
        $clone->save();

        $this->cloneRelations($model, $clone);

        $this->dispatchOnClonedEvent($clone, $model);

        return $clone;
    }

    /**
     * Clone a model instance to a specific database connection.
     *
     * @param  Model $model
     * @param  string $connection A Laravel database connection
     * @return Model The new model instance
     */
    public function duplicateTo($model, $connection) {
        $this->write_connection = $connection; // Store the write database connection
        $clone = $this->duplicate($model); // Do a normal duplicate
        $this->write_connection = null; // Null out the connection for next run

        return $clone;
    }

    /**
     * Create duplicate of the model.
     *
     * @param Model $model
     *
     * @return Model The new model instance
     */
    protected function cloneModel(Model $model): Model
    {
        if (method_exists($model, 'getCloneExemptAttributes')) {
            $exempt = $model->getCloneExemptAttributes();
        } else {
            $exempt = null;
        }

        $clone = $model->replicate($exempt);

        if ($this->write_connection) {
            $clone->setConnection($this->write_connection);
        }

        return $clone;
    }

    /**
     * Duplicate all attachments, given them a new name, and update the attribute
     * value.
     *
     * @param Model $model
     * @param Model $clone
     *
     * @return void
     */
    protected function duplicateAttachments(Model $model, Model $clone): void
    {
        if (! $this->attachment || ! method_exists($clone, 'getCloneableFileAttributes')) {
            return;
        }

        foreach($clone->getCloneableFileAttributes() as $attribute) {
            if (! $original = $model->getAttribute($attribute)) {
                continue;
            }

            $clone->setAttribute($attribute, $this->attachment->duplicate($original, $clone));
        }
    }

    /**
     * @param Model $clone
     * @param Relation|null $relation
     * @param Model|null $src The orginal model
     * @param bool|null $child
     *
     * @return void
     */
    protected function dispatchOnCloningEvent(Model $clone, ?Relation $relation = null, ?Model $src = null, ?bool $child = null): void
    {
        // Set the child flag
        if ($relation) {
            $child = true;
        }

        // Notify listeners via callback or event
        if (method_exists($clone, 'onCloning')) {
            $clone->onCloning($src, $child);
        }

        $this->events->dispatch('cloner::cloning: ' . get_class($src), [$clone, $src]);
    }

    /**
     * @param Model $clone
     * @param Model $src The orginal model
     *
     * @return void
     */
    protected function dispatchOnClonedEvent(Model $clone, Model $src): void
    {
        // Notify listeners via callback or event
        if (method_exists($clone, 'onCloned')) {
            $clone->onCloned($src);
        }

        $this->events->dispatch('cloner::cloned: ' . get_class($src), [$clone, $src]);
    }

    /**
     * Loop through relations and clone or re-attach them.
     *
     * @param  Model $model
     * @param  Model $clone
     * @return void
     */
    protected function cloneRelations($model, $clone): void
    {
        if (! method_exists($model, 'getCloneableRelations')) {
            return;
        }

        foreach($model->getCloneableRelations() as $relation_name) {
            $this->duplicateRelation($model, $relation_name, $clone);
        }
    }

    /**
     * Duplicate relationships to the clone.
     *
     * @param Model $model
     * @param string $relation_name
     * @param Model $clone
     *
     * @return void
     */
    protected function duplicateRelation(Model $model, string $relation_name, Model $clone): void
    {
        $relation = call_user_func([$model, $relation_name]);

        if (is_a($relation, BelongsToMany::class)) {
            $this->duplicatePivotedRelation($relation, $relation_name, $clone);
        } else {
            $this->duplicateDirectRelation($relation, $relation_name, $clone);
        }
    }

    /**
     * Duplicate a many-to-many style relation where we are just attaching the
     * relation to the dupe.
     *
     * @param Relation $relation
     * @param string $relation_name
     * @param Model $clone
     *
     * @return void
     */
    protected function duplicatePivotedRelation(Relation $relation, string $relation_name, Model $clone): void
    {

        // If duplicating between databases, do not duplicate relations. The related
        // instance may not exist in the other database or could have a different
        // primary key.
        if ($this->write_connection) {
            return;
        }

        // Loop trough current relations and attach to clone
        $relation->as('pivot')->get()->each(function ($foreign) use ($clone, $relation_name) {
            $pivot_attributes = Arr::except($foreign->pivot->getAttributes(), [
                $foreign->pivot->getRelatedKey(),
                $foreign->pivot->getForeignKey(),
                $foreign->pivot->getCreatedAtColumn(),
                $foreign->pivot->getUpdatedAtColumn(),
            ]);

            foreach (array_keys($pivot_attributes) as $attributeKey) {
                $pivot_attributes[$attributeKey] = $foreign->pivot->getAttribute($attributeKey);
            }

            if ($foreign->pivot->incrementing) {
                unset($pivot_attributes[$foreign->pivot->getKeyName()]);
            }

            $clone->$relation_name()->attach($foreign, $pivot_attributes);
        });
    }

    /**
     * Duplicate a one-to-many style relation where the foreign model is ALSO
     * cloned and then associated.
     *
     * @param Relation $relation
     * @param string $relation_name
     * @param Model $clone
     *
     * @return void
     */
    protected function duplicateDirectRelation(Relation $relation, string $relation_name, Model $clone): void
    {
        $relation->get()->each(function ($foreign) use ($clone, $relation_name) {
            $cloned_relation = $this->duplicate($foreign, $clone->$relation_name());
            if (is_a($clone->$relation_name(), BelongsTo::class)) {
                $clone->$relation_name()->associate($cloned_relation);
                $clone->save();
            }
        });
    }
}
