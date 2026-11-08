<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
| A release must be reversible by switching code back (deploy.sh rollback):
| migrations may add, never remove or rename, unless the risk was reviewed.
| A destructive step inside up() needs the comment
| `// migration-safety: <why it is safe>` in the same up() method.
*/

/**
 * @return string the body of the up() method
 */
function upBody(string $code): string
{
    if (preg_match('/function up\(\)[^{]*\{/', $code, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return '';
    }

    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    for ($i = $start, $n = strlen($code); $i < $n; $i++) {
        $depth += $code[$i] === '{' ? 1 : ($code[$i] === '}' ? -1 : 0);
        if ($depth === 0) {
            return substr($code, $start, $i - $start);
        }
    }

    return '';
}

it('needs a reviewed reason for every destructive step in a migration', function (): void {
    $patterns = [
        'dropColumn' => '/->dropColumn\(/', 'drop table' => '/Schema::(drop|dropIfExists)\(/', 'rename column' => '/->renameColumn\(/',
        'rename table' => '/Schema::rename\(/', 'column change' => '/->change\(\)/', 'raw drop' => '/DB::statement\([^)]*\b(DROP|TRUNCATE)\b/i',
        'truncate' => '/->truncate\(\)/',
    ];
    $unreviewed = [];

    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/database/migrations')->name('*.php') as $file) {
        $up = upBody((string) file_get_contents($file->getRealPath()));
        if (str_contains($up, '// migration-safety:')) {
            continue;
        }
        foreach ($patterns as $label => $pattern) {
            if (preg_match($pattern, $up) === 1) {
                $unreviewed[] = $file->getFilename().": {$label}";
            }
        }
    }

    expect($unreviewed)->toBe([]);
});

it('spots a destructive step that was not reviewed', function (): void {
    $code = "<?php return new class { public function up(): void { Schema::table('x', function (\$t) { \$t->dropColumn('y'); }); } public function down(): void {} };";

    expect(upBody($code))->toContain('dropColumn')
        ->and(upBody(str_replace("\$t->dropColumn('y');", "// migration-safety: column never used\n \$t->dropColumn('y');", $code)))->toContain('migration-safety');
});
