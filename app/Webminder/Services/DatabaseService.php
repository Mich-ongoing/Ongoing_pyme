<?php

namespace App\Webminder\Services;

use Illuminate\Support\Facades\DB;

class DatabaseService
{
    const DB_CLEAN_MAX_POSTS = 500;

    /**
     * Get database health metrics
     */
    public function getHealth(): array
    {
        return [
            'total_tables' => $this->getTablesCount(),
            'total_size_mb' => $this->getDatabaseSize(),
            'orphaned_postmeta' => $this->countOrphanedPostMeta(),
            'revision_posts' => $this->countRevisions(),
            'spam_comments' => $this->countSpamComments(),
            'trash_posts' => $this->countTrashPosts(),
        ];
    }

    /**
     * Clean database according to options
     */
    public function clean(array $options = []): array
    {
        $cleaned = [];
        $counters = [];

        // Clean revisions
        if ($options['clean_revisions'] ?? false) {
            $count = $this->cleanRevisions();
            $cleaned[] = 'clean_revisions';
            $counters['deleted_revisions'] = $count;
        }

        // Clean spam comments
        if ($options['clean_spam'] ?? false) {
            $count = $this->cleanSpamComments();
            $cleaned[] = 'clean_spam';
            $counters['deleted_spam_comments'] = $count;
        }

        // Clean orphaned post meta
        if ($options['clean_orphaned_meta'] ?? false) {
            $count = $this->cleanOrphanedPostMeta();
            $cleaned[] = 'clean_orphaned_meta';
            $counters['deleted_orphaned_meta'] = $count;
        }

        // Clean trash posts
        if ($options['clean_trash'] ?? false) {
            $count = $this->cleanTrashPosts();
            $cleaned[] = 'clean_trash';
            $counters['deleted_trash_posts'] = $count;
        }

        // Optimize tables
        if ($options['optimize_tables'] ?? false) {
            $count = $this->optimizeTables();
            $cleaned[] = 'optimize_tables';
            $counters['optimized_tables'] = $count;
        }

        return [
            'success' => true,
            'message' => 'Database cleanup completed',
            'cleaned' => $cleaned,
            ...$counters,
        ];
    }

    private function cleanRevisions(): int
    {
        // Delete post revisions (not auto-drafts)
        return DB::table('posts')
            ->where('post_type', 'revision')
            ->where('post_status', '!=', 'auto-draft')
            ->limit(self::DB_CLEAN_MAX_POSTS)
            ->delete();
    }

    private function cleanSpamComments(): int
    {
        // Delete spam comments
        return DB::table('comments')
            ->where('comment_approved', 'spam')
            ->limit(self::DB_CLEAN_MAX_POSTS)
            ->delete();
    }

    private function cleanOrphanedPostMeta(): int
    {
        // Delete postmeta for non-existent posts
        $orphanedIds = DB::table('postmeta')
            ->leftJoin('posts', 'postmeta.post_id', '=', 'posts.ID')
            ->whereNull('posts.ID')
            ->pluck('postmeta.meta_id')
            ->take(self::DB_CLEAN_MAX_POSTS);

        if ($orphanedIds->isEmpty()) {
            return 0;
        }

        return DB::table('postmeta')
            ->whereIn('meta_id', $orphanedIds)
            ->delete();
    }

    private function cleanTrashPosts(): int
    {
        // Permanently delete posts in trash (status = 'trash')
        return DB::table('posts')
            ->where('post_status', 'trash')
            ->limit(self::DB_CLEAN_MAX_POSTS)
            ->delete();
    }

    private function optimizeTables(): int
    {
        $optimized = 0;

        // Get all tables in current database
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $tableColumn = "Tables_in_$dbName";

        foreach ($tables as $table) {
            $tableName = $table->$tableColumn ?? null;
            if ($tableName) {
                try {
                    DB::statement("OPTIMIZE TABLE `$tableName`");
                    $optimized++;
                } catch (\Exception $e) {
                    // Continue on optimize failure
                }
            }
        }

        return $optimized;
    }

    private function getTablesCount(): int
    {
        return count(DB::select('SHOW TABLES'));
    }

    private function getDatabaseSize(): float
    {
        try {
            $result = DB::selectOne("
                SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE()
            ");
            return $result->size_mb ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function countOrphanedPostMeta(): int
    {
        try {
            return DB::table('postmeta')
                ->leftJoin('posts', 'postmeta.post_id', '=', 'posts.ID')
                ->whereNull('posts.ID')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function countRevisions(): int
    {
        try {
            return DB::table('posts')
                ->where('post_type', 'revision')
                ->where('post_status', '!=', 'auto-draft')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function countSpamComments(): int
    {
        try {
            return DB::table('comments')
                ->where('comment_approved', 'spam')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function countTrashPosts(): int
    {
        try {
            return DB::table('posts')
                ->where('post_status', 'trash')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
