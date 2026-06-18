<?php

declare(strict_types=1);

namespace SpotTest;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SpotTest\Entity\Post;

/**
 * Regression test for the relation-store memory leak.
 *
 * Spot\Entity::relation() keeps a process-lifetime function-static $relations
 * array keyed by each entity's unique ~55-character _objectId. Before the fix,
 * Entity::__destruct() only unset the INNER relation entries that had been
 * registered in self::$relationFields, leaving the OUTER $relations[$objectId]
 * bucket behind for the life of the process — and it never touched relations
 * stored via the setNull sentinel at all. Over a long-running worker that
 * hydrates many entities this accumulates to hundreds of MB.
 *
 * The fix drops the entire bucket via a RELATION_CLEAR_ALL sentinel, covering
 * every storage path (set / empty-collection / setNull). This test proves the
 * bucket no longer leaks by hydrating many entities, destroying them, and
 * asserting the process-static store has not grown.
 */
#[CoversNothing]
#[Group('memory')]
class RelationStoreLeakRegression extends TestCase
{
    public function testRelationStoreDoesNotLeakAfterEntityDestruction(): void
    {
        // Warm up: run one full cycle so any one-time allocations (autoloading,
        // class metadata, the $relationFields registry entry for Post, Zend MM
        // chunk reservations) happen before the baseline is captured.
        $this->hydrateAndDestroy(50);
        gc_collect_cycles();

        $before = memory_get_usage();

        // Hydrate and destroy a large batch. Under the bug each destroyed entity
        // leaves a leaked bucket keyed by its unique _objectId (~hundreds of
        // bytes each), so this batch would leak well over 500 KB. With the fix
        // the bucket is removed entirely and the drift stays near zero.
        $count = 2000;
        $this->hydrateAndDestroy($count);
        gc_collect_cycles();

        $after = memory_get_usage();
        $drift = $after - $before;

        // The 2000-entity leak the bug produces is > 500 KB. A clean run drifts
        // by at most a few KB of allocator noise. 100 KB sits far below the leak
        // and comfortably above the noise floor, so it fails loudly if the
        // bucket is ever left behind again while staying robust to GC jitter.
        $this->assertLessThan(
            100 * 1024,
            $drift,
            sprintf(
                'Relation store leaked %d bytes (%.1f bytes/entity) across %d destroyed entities; '
                . 'the __destruct() clear-all sentinel is not dropping the _objectId bucket.',
                $drift,
                $count > 0 ? $drift / $count : 0,
                $count,
            ),
        );
    }

    /**
     * Create $count entities, populate each one's relation bucket through every
     * storage path, then destroy it. Each entity is unset immediately so its
     * destructor runs inside the loop.
     */
    private function hydrateAndDestroy(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $entity = new Post(['title' => 'Leak test', 'author_id' => 1]);

            // SET path — registered in $relationFields and stored in the bucket.
            $entity->relation('author', new \stdClass());

            // setNull path — stores the RELATION_NULL sentinel and is NOT
            // registered in $relationFields, so the old destructor never
            // cleared it. This is the path the clear-all sentinel must cover.
            $entity->relation('comments', null, true);

            // Destroying the only reference triggers __destruct().
            unset($entity);
        }
    }
}
