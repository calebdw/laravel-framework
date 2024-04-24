<?php

namespace Illuminate\Database\Eloquent;

use Illuminate\Contracts\Queue\QueueableCollection;
use Illuminate\Contracts\Queue\QueueableEntity;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithDictionary;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as BaseCollection;
use LogicException;
use UnexpectedValueException;

/**
 * @template TKey of array-key
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends \Illuminate\Support\Collection<TKey, TModel>
 */
class Collection extends BaseCollection implements QueueableCollection
{
    use InteractsWithDictionary;

    /**
     * Create a new collection.
     *
     * @param  \Illuminate\Contracts\Support\Arrayable<TKey, TModel>|iterable<TKey, TModel>|null  $items
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    public function __construct($items = [])
    {
        parent::__construct($items);
        $this->ensureItemsAreModels();
    }

    /**
     * Create a new collection instance if the value isn't one already.
     *
     * @template TMakeKey of array-key
     * @template TMakeValue
     *
     * @param  \Illuminate\Contracts\Support\Arrayable<TMakeKey, TMakeValue>|iterable<TMakeKey, TMakeValue>|null  $items
     * @return \Illuminate\Support\Collection<TMakeKey, TMakeValue>|static<TMakeKey, TMakeValue>
     */
    public static function make($items = [])
    {
        try {
            return parent::make($items);
        } catch (UnexpectedValueException $e) {
            return BaseCollection::make($items);
        }
    }

    /**
     * Create a Support collection with the given range.
     *
     * @param  int  $from
     * @param  int  $to
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function range($from, $to)
    {
        return BaseCollection::range($from, $to);
    }

    /**
     * Wrap the given value in a collection if applicable.
     *
     * @template TWrapValue
     *
     * @param  iterable<array-key, TWrapValue>|TWrapValue  $value
     * @return \Illuminate\Support\Collection<array-key, TWrapValue>|static<array-key, TWrapValue>
     */
    public static function wrap($value)
    {
        try {
            return parent::wrap($value);
        } catch (UnexpectedValueException $e) {
            return BaseCollection::wrap($value);
        }
    }

    /**
     * Find a model in the collection by key.
     *
     * @template TFindDefault
     *
     * @param  mixed  $key
     * @param  TFindDefault  $default
     * @return static<TKey, TModel>|TModel|TFindDefault
     */
    public function find($key, $default = null)
    {
        if ($key instanceof Model) {
            $key = $key->getKey();
        }

        if ($key instanceof Arrayable) {
            $key = $key->toArray();
        }

        if (is_array($key)) {
            if ($this->isEmpty()) {
                return new static;
            }

            return $this->whereIn($this->first()->getKeyName(), $key);
        }

        return Arr::first($this->items, fn ($model) => $model->getKey() == $key, $default);
    }

    /**
     * Load a set of relationships onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @return $this
     */
    public function load($relations)
    {
        if ($this->isNotEmpty()) {
            if (is_string($relations)) {
                $relations = func_get_args();
            }

            $query = $this->first()->newQueryWithoutRelationships()->with($relations);

            $this->items = $query->eagerLoadRelations($this->items);
        }

        return $this;
    }

    /**
     * Load a set of aggregations over relationship's column onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @param  string  $column
     * @param  string|null  $function
     * @return $this
     */
    public function loadAggregate($relations, $column, $function = null)
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $models = $this->first()->newModelQuery()
            ->whereKey($this->modelKeys())
            ->select($this->first()->getKeyName())
            ->withAggregate($relations, $column, $function)
            ->get()
            ->keyBy($this->first()->getKeyName());

        $attributes = Arr::except(
            array_keys($models->first()->getAttributes()),
            $models->first()->getKeyName()
        );

        $this->each(function ($model) use ($models, $attributes) {
            $extraAttributes = Arr::only($models->get($model->getKey())->getAttributes(), $attributes);

            $model->forceFill($extraAttributes)
                ->syncOriginalAttributes($attributes)
                ->mergeCasts($models->get($model->getKey())->getCasts());
        });

        return $this;
    }

    /**
     * Load a set of relationship counts onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @return $this
     */
    public function loadCount($relations)
    {
        return $this->loadAggregate($relations, '*', 'count');
    }

    /**
     * Load a set of relationship's max column values onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadMax($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'max');
    }

    /**
     * Load a set of relationship's min column values onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadMin($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'min');
    }

    /**
     * Load a set of relationship's column summations onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadSum($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'sum');
    }

    /**
     * Load a set of relationship's average column values onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadAvg($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'avg');
    }

    /**
     * Load a set of related existences onto the collection.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @return $this
     */
    public function loadExists($relations)
    {
        return $this->loadAggregate($relations, '*', 'exists');
    }

    /**
     * Load a set of relationships onto the collection if they are not already eager loaded.
     *
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>|string  $relations
     * @return $this
     */
    public function loadMissing($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        foreach ($relations as $key => $value) {
            if (is_numeric($key)) {
                $key = $value;
            }

            $segments = explode('.', explode(':', $key)[0]);

            if (str_contains($key, ':')) {
                $segments[count($segments) - 1] .= ':'.explode(':', $key)[1];
            }

            $path = [];

            foreach ($segments as $segment) {
                $path[] = [$segment => $segment];
            }

            if (is_callable($value)) {
                $path[count($segments) - 1][end($segments)] = $value;
            }

            $this->loadMissingRelation($this, $path);
        }

        return $this;
    }

    /**
     * Load a relationship path if it is not already eager loaded.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @param  array  $path
     * @return void
     */
    protected function loadMissingRelation(self $models, array $path)
    {
        $relation = array_shift($path);

        $name = explode(':', key($relation))[0];

        if (is_string(reset($relation))) {
            $relation = reset($relation);
        }

        $models->filter(fn ($model) => ! is_null($model) && ! $model->relationLoaded($name))->load($relation);

        if (empty($path)) {
            return;
        }

        $models = $models->pluck($name)->whereNotNull();

        if ($models->first() instanceof BaseCollection) {
            $models = $models->collapse();
        }

        $this->loadMissingRelation(new static($models), $path);
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param  string  $relation
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>  $relations
     * @return $this
     */
    public function loadMorph($relation, $relations)
    {
        $this->pluck($relation)
            ->filter()
            ->groupBy(fn ($model) => get_class($model))
            ->each(fn ($models, $className) => static::make($models)->load($relations[$className] ?? []));

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     *
     * @param  string  $relation
     * @param  array<array-key, (callable(\Illuminate\Database\Eloquent\Builder): mixed)|string>  $relations
     * @return $this
     */
    public function loadMorphCount($relation, $relations)
    {
        $this->pluck($relation)
            ->filter()
            ->groupBy(fn ($model) => get_class($model))
            ->each(fn ($models, $className) => static::make($models)->loadCount($relations[$className] ?? []));

        return $this;
    }

    /**
     * Determine if a key exists in the collection.
     *
     * @param  (callable(TModel, TKey): bool)|TModel|string|int  $key
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return bool
     */
    public function contains($key, $operator = null, $value = null)
    {
        if (func_num_args() > 1 || $this->useAsCallable($key)) {
            return parent::contains(...func_get_args());
        }

        if ($key instanceof Model) {
            return parent::contains(fn ($model) => $model->is($key));
        }

        return parent::contains(fn ($model) => $model->getKey() == $key);
    }

    /**
     * Get the array of primary keys.
     *
     * @return array<int, array-key>
     */
    public function modelKeys()
    {
        return array_map(fn ($model) => $model->getKey(), $this->items);
    }

    /**
     * Merge the collection with the given items.
     *
     * @template TValue
     *
     * @param  iterable<array-key, TModel|TValue>  $items
     * @return \Illuminate\Support\Collection<TKey, TModel|TValue>|static<TKey, TModel>
     */
    public function merge($items)
    {
        try {
            $this->ensureItemsAreModels($items);
        } catch (UnexpectedValueException) {
            return $this->toBase()->merge($items);
        }

        $dictionary = $this->getDictionary();

        foreach ($items as $item) {
            $dictionary[$this->getDictionaryKey($item->getKey())] = $item;
        }

        return new static(array_values($dictionary));
    }

    /**
     * Reload a fresh model instance from the database for all the entities.
     *
     * @param  array<array-key, string>|string  $with
     * @return static<int, TModel>
     */
    public function fresh($with = [])
    {
        if ($this->isEmpty()) {
            return new static;
        }

        $model = $this->first();

        $freshModels = $model->newQueryWithoutScopes()
            ->with(is_string($with) ? func_get_args() : $with)
            ->whereIn($model->getKeyName(), $this->modelKeys())
            ->get()
            ->getDictionary();

        return $this->filter(fn ($model) => $model->exists && isset($freshModels[$model->getKey()]))
            ->map(fn ($model) => $freshModels[$model->getKey()]);
    }

    /**
     * Diff the collection with the given items.
     *
     * @param  iterable<array-key, TModel>  $items
     * @return static<int, TModel>
     */
    public function diff($items)
    {
        $diff = new static;

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (! isset($dictionary[$this->getDictionaryKey($item->getKey())])) {
                $diff->add($item);
            }
        }

        return $diff;
    }

    /**
     * Intersect the collection with the given items.
     *
     * @param  iterable<array-key, TModel>  $items
     * @return static<int, TModel>
     */
    public function intersect($items)
    {
        $intersect = new static;

        if (empty($items)) {
            return $intersect;
        }

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (isset($dictionary[$this->getDictionaryKey($item->getKey())])) {
                $intersect->add($item);
            }
        }

        return $intersect;
    }

    /**
     * Return only unique items from the collection.
     *
     * @param  (callable(TModel, TKey): mixed)|string|null  $key
     * @param  bool  $strict
     * @return static<int, TModel>
     */
    public function unique($key = null, $strict = false)
    {
        if (! is_null($key)) {
            return parent::unique($key, $strict);
        }

        return new static(array_values($this->getDictionary()));
    }

    /**
     * Returns only the models from the collection with the specified keys.
     *
     * @param  array<array-key, mixed>|null  $keys
     * @return static<int, TModel>
     */
    public function only($keys)
    {
        if (is_null($keys)) {
            return new static($this->items);
        }

        $dictionary = Arr::only($this->getDictionary(), array_map($this->getDictionaryKey(...), (array) $keys));

        return new static(array_values($dictionary));
    }

    /**
     * Returns all models in the collection except the models with specified keys.
     *
     * @param  array<array-key, mixed>|null  $keys
     * @return static<int, TModel>
     */
    public function except($keys)
    {
        if (is_null($keys)) {
            return new static($this->items);
        }

        $dictionary = Arr::except($this->getDictionary(), array_map($this->getDictionaryKey(...), (array) $keys));

        return new static(array_values($dictionary));
    }

    /**
     * Make the given, typically visible, attributes hidden across the entire collection.
     *
     * @param  array<array-key, string>|string  $attributes
     * @return $this
     */
    public function makeHidden($attributes)
    {
        return $this->each->makeHidden($attributes);
    }

    /**
     * Make the given, typically hidden, attributes visible across the entire collection.
     *
     * @param  array<array-key, string>|string  $attributes
     * @return $this
     */
    public function makeVisible($attributes)
    {
        return $this->each->makeVisible($attributes);
    }

    /**
     * Set the visible attributes across the entire collection.
     *
     * @param  array<int, string>  $visible
     * @return $this
     */
    public function setVisible($visible)
    {
        return $this->each->setVisible($visible);
    }

    /**
     * Set the hidden attributes across the entire collection.
     *
     * @param  array<int, string>  $hidden
     * @return $this
     */
    public function setHidden($hidden)
    {
        return $this->each->setHidden($hidden);
    }

    /**
     * Append an attribute across the entire collection.
     *
     * @param  array<array-key, string>|string  $attributes
     * @return $this
     */
    public function append($attributes)
    {
        return $this->each->append($attributes);
    }

    /**
     * Get a dictionary keyed by primary keys.
     *
     * @param  iterable<array-key, TModel>|null  $items
     * @return array<array-key, TModel>
     */
    public function getDictionary($items = null)
    {
        $items = is_null($items) ? $this->items : $items;

        $dictionary = [];

        foreach ($items as $value) {
            $dictionary[$this->getDictionaryKey($value->getKey())] = $value;
        }

        return $dictionary;
    }

    /**
     * The following methods are intercepted to always return base collections.
     */

    /**
     * Push all of the given items onto the collection.
     *
     * @template TConcatKey of array-key
     * @template TConcatValue
     *
     * @param  iterable<TConcatKey, TConcatValue>  $source
     * @return \Illuminate\Support\Collection<TKey|TConcatKey, TModel|TConcatValue>|static<TKey|TConcatKey, TModel|TConcatValue>
     */
    public function concat($source)
    {
        try {
            $this->ensureItemsAreModels($source);
        } catch (UnexpectedValueException) {
            return $this->toBase()->concat($source);
        }

        return parent::concat($source);
    }

    /**
     * Count the number of items in the collection by a field or using a callback.
     *
     * @param  (callable(TModel, TKey): array-key)|string|null  $countBy
     * @return \Illuminate\Support\Collection<array-key, int>
     */
    public function countBy($countBy = null)
    {
        return $this->toBase()->countBy($countBy);
    }

    /**
     * Collapse the collection of items into a single array.
     *
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function collapse()
    {
        return $this->toBase()->collapse();
    }

    /**
     * Cross join with the given lists, returning all possible permutations.
     *
     * @template TCrossJoinKey
     * @template TCrossJoinValue
     *
     * @param  \Illuminate\Contracts\Support\Arrayable<TCrossJoinKey, TCrossJoinValue>|iterable<TCrossJoinKey, TCrossJoinValue>  ...$lists
     * @return \Illuminate\Support\Collection<int, array<int, TModel|TCrossJoinValue>>
     */
    public function crossJoin(...$lists)
    {
        return $this->toBase()->crossJoin(...$lists);
    }

    /**
     * Get a flattened array of the items in the collection.
     *
     * @param  int  $depth
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    public function flatten($depth = INF)
    {
        return $this->toBase()->flatten($depth);
    }

    /**
     * Flip the items in the collection.
     *
     * @return \Illuminate\Support\Collection<array-key, TKey>
     */
    public function flip()
    {
        return $this->toBase()->flip();
    }

    /**
     * Get the keys of the collection items.
     *
     * @return \Illuminate\Support\Collection<int, TKey>
     */
    public function keys()
    {
        return $this->toBase()->keys();
    }

    /**
     * Pad collection to the specified length with a value.
     *
     * @template TPadValue
     *
     * @param  int  $size
     * @param  TPadValue  $value
     * @return \Illuminate\Support\Collection<int, TModel|TPadValue>
     */
    public function pad($size, $value)
    {
        return $this->toBase()->pad($size, $value);
    }

    /**
     * Get an array with the values of a given key.
     *
     * @param  string|array<array-key, string>  $value
     * @param  string|null  $key
     * @return \Illuminate\Support\Collection<array-key, mixed>
     */
    public function pluck($value, $key = null)
    {
        return $this->toBase()->pluck($value, $key);
    }

    /**
     * Push an item onto the beginning of the collection.
     *
     * @param  TModel  $value
     * @param  TKey  $key
     * @return $this
     *
     * @throws \UnexpectedValueException
     */
    public function prepend($value, $key = null)
    {
        $this->ensureItemsAreModels($value);

        return parent::prepend($value, $key);
    }

    /**
     * Push one or more items onto the end of the collection.
     *
     * @param  TModel ...$values
     * @return $this
     */
    public function push(...$values)
    {
        $this->ensureItemsAreModels($values);

        return parent::push(...$values);
    }

    /**
     * Transform each item in the collection using a callback.
     *
     * @param  callable(TModel, TKey): TModel  $callback
     * @return $this
     *
     * @throws \UnexpectedValueException
     */
    public function transform(callable $callback)
    {
        parent::transform($callback);
        $this->ensureItemsAreModels();

        return $this;
    }

    /**
     * Zip the collection together with one or more arrays.
     *
     * @template TZipValue
     *
     * @param  \Illuminate\Contracts\Support\Arrayable<array-key, TZipValue>|iterable<array-key, TZipValue>  ...$items
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, TModel|TZipValue>>
     */
    public function zip($items)
    {
        return $this->toBase()->zip(...func_get_args());
    }

    /**
     * Get the comparison function to detect duplicates.
     *
     * @param  bool  $strict
     * @return callable(TModel, TModel): bool
     */
    protected function duplicateComparator($strict)
    {
        return fn ($a, $b) => $a->is($b);
    }

    /**
     * Get the type of the entities being queued.
     *
     * @return string|null
     *
     * @throws \LogicException
     */
    public function getQueueableClass()
    {
        if ($this->isEmpty()) {
            return;
        }

        $class = $this->getQueueableModelClass($this->first());

        $this->each(function ($model) use ($class) {
            if ($this->getQueueableModelClass($model) !== $class) {
                throw new LogicException('Queueing collections with multiple model types is not supported.');
            }
        });

        return $class;
    }

    /**
     * Get the queueable class name for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return string
     */
    protected function getQueueableModelClass($model)
    {
        return method_exists($model, 'getQueueableClassName')
                ? $model->getQueueableClassName()
                : get_class($model);
    }

    /**
     * Get the identifiers for all of the entities.
     *
     * @return array<int, mixed>
     */
    public function getQueueableIds()
    {
        if ($this->isEmpty()) {
            return [];
        }

        return $this->first() instanceof QueueableEntity
                    ? $this->map->getQueueableId()->all()
                    : $this->modelKeys();
    }

    /**
     * Get the relationships of the entities being queued.
     *
     * @return array<int, string>
     */
    public function getQueueableRelations()
    {
        if ($this->isEmpty()) {
            return [];
        }

        $relations = $this->map->getQueueableRelations()->all();

        if (count($relations) === 0 || $relations === [[]]) {
            return [];
        } elseif (count($relations) === 1) {
            return reset($relations);
        } else {
            return array_intersect(...array_values($relations));
        }
    }

    /**
     * Get the connection of the entities being queued.
     *
     * @return string|null
     *
     * @throws \LogicException
     */
    public function getQueueableConnection()
    {
        if ($this->isEmpty()) {
            return;
        }

        $connection = $this->first()->getConnectionName();

        $this->each(function ($model) use ($connection) {
            if ($model->getConnectionName() !== $connection) {
                throw new LogicException('Queueing collections with multiple model connections is not supported.');
            }
        });

        return $connection;
    }

    /**
     * Get the Eloquent query builder from the collection.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     *
     * @throws \LogicException
     */
    public function toQuery()
    {
        $model = $this->first();

        if (! $model) {
            throw new LogicException('Unable to create query for empty collection.');
        }

        $class = get_class($model);

        if ($this->filter(fn ($model) => ! $model instanceof $class)->isNotEmpty()) {
            throw new LogicException('Unable to create query for collection with mixed types.');
        }

        return $model->newModelQuery()->whereKey($this->modelKeys());
    }

    /**
     * Set the item at a given offset.
     *
     * @param  TKey|null  $key
     * @param  TModel  $value
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    public function offsetSet($key, $value): void
    {
        $this->ensureItemsAreModels($value);
        parent::offsetSet($key, $value);
    }

    /**
     * Ensure that all items are Model instances.
     *
     * @param  iterable<array-key, TModel>|TModel|null  $items
     * @return void
     *
     * @throws \UnexpectedValueException
     */
    protected function ensureItemsAreModels($items = null): void
    {
        BaseCollection::wrap($items ?? $this->items)->ensure(Model::class);
    }
}
