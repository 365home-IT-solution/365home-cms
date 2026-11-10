<?php

// Sinh tài liệu OpenAPI 3.0 cho TOÀN BỘ API (/api/*) của 365 HOME, cùng định dạng file 365sport-all.openapi.yaml:
// chia theo loại tài khoản (CLIENT · TENANT · ADMIN · SYSTEM) rồi đến module (scripts/openapi/modules.php).
//
// Nguồn dữ liệu (KHÔNG gọi thử API — CSDL local là bản sao dữ liệu thật):
//  - route thật đang đăng ký + middleware (đăng nhập, loại đối tác, giới hạn tần suất);
//  - chú thích của controller (docblock / // phía trên method, dòng "METHOD /api/..." trong docblock của class);
//  - luật validate trong mã nguồn ($request->validate, Validator::make — kể cả luật để trong biến / method / hằng, và trong service nhận $request);
//  - trường đọc thẳng từ request ($request->input('x'), ->file('x')...);
//  - response()->json([...]) trong method (khoá + giá trị cố định), abort(...) và ValidationException;
//  - kiểm tra quyền: ->can('quyền') / hasPermission(..., 'quyền'), chỉ Super Admin (authorizeSuperAdmin, abort_unless(isSuperAdmin)...).
//
// Chạy: php scripts/generate_openapi.php [đường dẫn file ra]   (mặc định docs/api/365home-all.openapi.yaml)

declare(strict_types=1);

use Illuminate\Routing\Route;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Yaml\Yaml;

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
$app = require $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$out = $argv[1] ?? $base . '/docs/api/365home-all.openapi.yaml';
$cfg = require __DIR__ . '/openapi/modules.php';
$labels = require __DIR__ . '/openapi/labels.php';

$gen = new OpenApiGenerator($cfg, $labels);
$doc = $gen->build(app('router'));

if ($gen->unmatched) {
    fwrite(STDERR, "Route chưa xếp module (bổ sung vào scripts/openapi/modules.php):\n  " . implode("\n  ", $gen->unmatched) . "\n");
    exit(1);
}

@mkdir(dirname($out), 0777, true);
$yaml = Yaml::dump($doc, 30, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP);
// Mã phản hồi phải là chuỗi ('200':) theo OpenAPI — khoá mảng PHP '200' bị đổi thành số nguyên khi dump.
$yaml = preg_replace('/^(\s+)([1-5]\d\d):$/m', "$1'$2':", $yaml);
file_put_contents($out, $yaml);
echo 'Đã ghi ' . $gen->count . ' thao tác → ' . $out . PHP_EOL;
foreach ($gen->stats as $k => $v) {
    echo "  $k: $v" . PHP_EOL;
}

final class OpenApiGenerator
{
    public array $unmatched = [];

    public int $count = 0;

    public array $stats = [];

    private array $ast = [];

    private array $lines = [];

    private $parser;

    private NodeFinder $finder;

    private const ACCOUNT_BADGE = ['CLIENT' => 'CLIENT', 'TENANT' => 'KHÁCH_THUÊ', 'SYSTEM' => 'TÍCH_HỢP'];

    private const CONTEXT = ['api', 'admin', 'v1', 'v2', 'public', 'minihouse', 'portal', 'guest'];

    public function __construct(private array $cfg, private array $labels)
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->finder = new NodeFinder();
    }

    public function build($router): array
    {
        $ops = [];
        $tagCount = [];

        $routes = collect($router->getRoutes()->getRoutes())
            ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
            ->sortBy(fn (Route $r) => $r->uri())
            ->values();

        foreach ($routes as $route) {
            $path = '/' . preg_replace('#\{(\w+)\?\}#', '{$1}', substr($route->uri(), 4));
            $module = $this->moduleFor($path);
            if ($module === null) {
                $this->unmatched[] = implode('|', $route->methods()) . ' ' . $path;
                continue;
            }

            $middleware = array_map('strval', $router->gatherRouteMiddleware($route));
            $account = $this->accountFor($route, $path, $middleware);
            $info = $this->analyse($route);

            foreach (array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS'])) as $method) {
                $tag = $this->tagName($account, $module);
                $tagCount[$tag] = ($tagCount[$tag] ?? 0) + 1;
                $ops[$path][strtolower($method)] = $this->operation($route, $method, $path, $account, $module, $middleware, $info, $tag);
                $this->count++;
                $this->stats[$account] = ($this->stats[$account] ?? 0) + 1;
            }
        }

        ksort($ops);

        return $this->document($ops, $tagCount);
    }

    // ── Phân loại ───────────────────────────────────────────────────────────────────────────────────

    private function moduleFor(string $path): ?string
    {
        foreach ($this->cfg['rules'] as [$regex, $module]) {
            if (preg_match($regex, $path)) {
                return (string) $module;
            }
        }

        return null;
    }

    private function accountFor(Route $route, string $path, array $mw): string
    {
        if (preg_match('#^/(webhook|minihouse/webhook)/|^/lock/callback$|^/ttlock-card-app/|^/camera-media/#', $path)) {
            return 'SYSTEM';
        }
        if (str_starts_with($path, '/admin/') || $this->hasMw($mw, 'AdminApiAuth')) {
            return 'ADMIN';
        }
        if (str_starts_with($path, '/minihouse/portal/') || $this->hasMw($mw, 'TenantApiAuth')) {
            return 'TENANT';
        }

        return 'CLIENT';
    }

    private function hasMw(array $mw, string $needle): bool
    {
        foreach ($mw as $m) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function tagName(string $account, string|int $module): string
    {
        $module = sprintf('%02d', (int) $module); // khoá '10'..'23' bị PHP đổi thành số nguyên

        return $account . ' · ' . $module . ' ' . $this->cfg['modules'][$module][0];
    }

    // ── Phân tích controller ────────────────────────────────────────────────────────────────────────

    private function analyse(Route $route): array
    {
        $info = ['comment' => '', 'rules' => [], 'responses' => [], 'errors' => [], 'perms' => [], 'super' => false, 'owner' => false, 'method' => '', 'class' => '', 'stream' => false];
        $uses = $route->getAction('uses');
        if (! is_string($uses)) {
            $info['comment'] = 'Xử lý trực tiếp trong file route (closure).';

            return $info;
        }

        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
        $info['class'] = $class;
        $info['method'] = $method;

        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return $info;
        }

        $file = $ref->getFileName(); // file định nghĩa method (có thể là trait)
        if (! $file || ! is_file($file)) {
            return $info;
        }

        $info['comment'] = $this->commentFor($ref, $file, $route);
        [$classNode, $methodNode] = $this->findMethod($file, $method);
        if (! $methodNode) {
            return $info;
        }

        // Method chính + các method $this->x() nó gọi (2 tầng) + constructor: nơi đặt validate / kiểm tra quyền.
        $bodies = $this->relatedMethods($classNode, $methodNode, 2);
        if ($ctor = $this->classMethod($classNode, '__construct')) {
            $bodies[] = $ctor;
        }
        $owners = array_fill(0, count($bodies), $classNode);

        // Service nhận nguyên $request (validate / đọc input bên trong service): $svc->x($request), $this->svc->x($request), Svc::x($request)
        foreach ($this->serviceMethods($ref, $bodies) as [$svcClass, $svcMethod]) {
            $bodies[] = $svcMethod;
            $owners[] = $svcClass;
        }

        $read = [];
        foreach ($bodies as $i => $node) {
            $this->collectRules($node, $owners[$i], $info['rules']);
            $this->collectReads($node, $read);
            $this->collectAuth($node, $info);
            if ($i === 0) {
                $this->collectResponses($node, $info);
            } else {
                $this->collectErrors($node, $info);
            }
        }
        // Trường đọc thẳng từ request (không qua validate) → khai báo là tuỳ chọn.
        foreach ($read as $field => $rule) {
            if (! isset($info['rules'][$field]) && ! $this->coveredByRules($field, $info['rules'])) {
                $info['rules'][$field] = array_values(array_filter(['nullable', $rule]));
            }
        }

        return $info;
    }

    /**
     * Method của lớp khác (service, controller khác) được gọi với tham số $request — trả [[ClassLike, ClassMethod], ...].
     * Kiểu của biến lấy từ tham số method / constructor (thuộc tính promoted) / thuộc tính có khai báo kiểu.
     */
    private function serviceMethods(ReflectionMethod $ref, array $bodies): array
    {
        $types = [];
        foreach ($ref->getParameters() as $p) {
            if (($t = $p->getType()) instanceof ReflectionNamedType && ! $t->isBuiltin()) {
                $types['$' . $p->getName()] = $t->getName();
            }
        }
        $cls = $ref->getDeclaringClass();
        if ($ctor = $cls->getConstructor()) {
            foreach ($ctor->getParameters() as $p) {
                if (($t = $p->getType()) instanceof ReflectionNamedType && ! $t->isBuiltin()) {
                    $types['->' . $p->getName()] = $t->getName();
                }
            }
        }
        foreach ($cls->getProperties() as $prop) {
            if (($t = $prop->getType()) instanceof ReflectionNamedType && ! $t->isBuiltin()) {
                $types['->' . $prop->getName()] ??= $t->getName();
            }
        }

        $found = [];
        foreach ($bodies as $body) {
            foreach ($this->finder->find($body, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall) && $n->name instanceof Node\Identifier) as $call) {
                $passesRequest = false;
                foreach ($call->getArgs() as $a) {
                    if ($a->value instanceof Node\Expr\Variable && $a->value->name === 'request') {
                        $passesRequest = true;
                    }
                }
                if (! $passesRequest) {
                    continue;
                }
                $class = null;
                if ($call instanceof Node\Expr\StaticCall && $call->class instanceof Node\Name) {
                    $class = $call->class->toString();
                    if (in_array(strtolower($class), ['self', 'static', 'parent'], true)) {
                        continue;
                    }
                    if (! class_exists($class)) {
                        $class = $this->resolveImported($ref->getFileName(), $class);
                    }
                } elseif ($call->var instanceof Node\Expr\Variable && is_string($call->var->name)) {
                    $class = $types['$' . $call->var->name] ?? null;
                } elseif ($call->var instanceof Node\Expr\PropertyFetch && $call->var->var instanceof Node\Expr\Variable && $call->var->var->name === 'this' && $call->var->name instanceof Node\Identifier) {
                    $class = $types['->' . $call->var->name->toString()] ?? null;
                }
                if (! $class || str_starts_with($class, 'Illuminate\\') || ! class_exists($class)) {
                    continue;
                }
                $key = $class . '::' . $call->name->toString();
                if (isset($found[$key])) {
                    continue;
                }
                try {
                    $m = new ReflectionMethod($class, $call->name->toString());
                } catch (Throwable) {
                    continue;
                }
                if (! ($file = $m->getFileName())) {
                    continue;
                }
                [$classNode, $methodNode] = $this->findMethod($file, $m->getName());
                if ($methodNode) {
                    $found[$key] = [$classNode, $methodNode];
                    foreach ($this->relatedMethods($classNode, $methodNode, 1) as $extra) {
                        $found[$key . '#' . $extra->name->toString()] = [$classNode, $extra];
                    }
                }
            }
        }

        return array_values($found);
    }

    private function resolveImported(string $file, string $short): ?string
    {
        foreach ($this->finder->find($this->parse($file), fn (Node $n) => $n instanceof Node\UseItem) as $use) {
            $name = $use->name->toString();
            $alias = $use->alias?->toString() ?? substr(strrchr('\\' . $name, '\\'), 1);
            if ($alias === $short) {
                return $name;
            }
        }
        $ns = $this->finder->findFirst($this->parse($file), fn (Node $n) => $n instanceof Node\Stmt\Namespace_);

        return $ns?->name ? $ns->name->toString() . '\\' . $short : null;
    }

    /** $request->input('x') / ->boolean('x') / ->file('x') / ->only([...]) ... → [trường => luật kiểu tương ứng]. */
    private function collectReads(Node $method, array &$read): void
    {
        $types = ['input' => '', 'get' => '', 'post' => '', 'query' => '', 'string' => 'string', 'str' => 'string', 'boolean' => 'boolean', 'bool' => 'boolean',
            'integer' => 'integer', 'int' => 'integer', 'float' => 'numeric', 'date' => 'date', 'file' => 'file', 'hasFile' => 'file', 'filled' => '', 'has' => '',
            'json' => '', 'collect' => 'array', 'array' => 'array', 'enum' => '', 'only' => ''];

        // Tham số có giá trị mặc định chuỗi (vd string $key = 'cccd_qr_image') — dùng khi tên trường truyền qua biến
        $defaults = [];
        if ($method instanceof Node\Stmt\ClassMethod) {
            foreach ($method->params as $p) {
                if ($p->var instanceof Node\Expr\Variable && is_string($p->var->name) && ($d = $this->stringValue($p->default)) !== null) {
                    $defaults[$p->var->name] = $d;
                }
            }
        }

        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier
            && $n->var instanceof Node\Expr\Variable && in_array($n->var->name, ['request', 'req'], true)) as $call) {
            $name = $call->name->toString();
            if (! array_key_exists($name, $types) || ! ($arg = $call->getArgs()[0] ?? null)) {
                continue;
            }
            $v = $arg->value;
            if ($v instanceof Node\Expr\Array_) {
                $keys = $this->literalList($v);
            } elseif ($v instanceof Node\Expr\Variable && is_string($v->name) && isset($defaults[$v->name])) {
                $keys = [$defaults[$v->name]];
            } else {
                $keys = array_filter([$this->stringValue($v)]);
            }
            foreach ($keys as $k) {
                if ($k === '' || str_contains($k, ' ') || preg_match('/^[A-Z]/', $k)) {
                    continue;
                }
                if (! isset($read[$k]) || $read[$k] === '') {
                    $read[$k] = $types[$name];
                }
            }
        }
    }

    private function coveredByRules(string $field, array $rules): bool
    {
        foreach (array_keys($rules) as $k) {
            if (str_starts_with((string) $k, $field . '.')) {
                return true;
            }
        }

        return false;
    }

    private function parse(string $file): array
    {
        if (! isset($this->ast[$file])) {
            $code = file_get_contents($file);
            $this->lines[$file] = preg_split('/\R/u', $code);
            try {
                $this->ast[$file] = $this->parser->parse($code) ?? [];
            } catch (Throwable) {
                $this->ast[$file] = [];
            }
        }

        return $this->ast[$file];
    }

    // Lớp / trait trong file có chứa method này (method có thể đến từ trait).
    private function findMethod(string $file, string $method): array
    {
        foreach ($this->finder->find($this->parse($file), fn (Node $n) => $n instanceof Node\Stmt\ClassLike) as $class) {
            if ($m = $this->classMethod($class, $method)) {
                return [$class, $m];
            }
        }

        return [null, null];
    }

    private function classMethod(?Node\Stmt\ClassLike $class, string $name): ?Node\Stmt\ClassMethod
    {
        if (! $class) {
            return null;
        }
        foreach ($class->getMethods() as $m) {
            if (strcasecmp($m->name->toString(), $name) === 0) {
                return $m;
            }
        }

        return null;
    }

    private function relatedMethods(Node\Stmt\ClassLike $class, Node\Stmt\ClassMethod $start, int $depth): array
    {
        $seen = [$start->name->toString() => $start];
        $frontier = [$start];
        for ($d = 0; $d < $depth; $d++) {
            $next = [];
            foreach ($frontier as $m) {
                foreach ($this->finder->find($m, fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->var instanceof Node\Expr\Variable && $n->var->name === 'this' && $n->name instanceof Node\Identifier) as $call) {
                    $name = $call->name->toString();
                    if (! isset($seen[$name]) && ($cm = $this->classMethod($class, $name))) {
                        $seen[$name] = $cm;
                        $next[] = $cm;
                    }
                }
            }
            $frontier = $next;
        }

        return array_values($seen);
    }

    // ── Chú thích ───────────────────────────────────────────────────────────────────────────────────

    private function commentFor(ReflectionMethod $ref, string $file, Route $route): string
    {
        $this->parse($file);
        $text = $ref->getDocComment() ?: '';

        if ($text === '') {
            // Khối // ngay phía trên method (bỏ qua attribute #[...])
            $lines = $this->lines[$file];
            $collected = [];
            for ($i = $ref->getStartLine() - 2; $i >= 0; $i--) {
                $l = trim($lines[$i] ?? '');
                if (str_starts_with($l, '#[')) {
                    continue;
                }
                if (str_starts_with($l, '//')) {
                    array_unshift($collected, $l);
                    continue;
                }
                if (str_ends_with($l, '*/')) {
                    $block = [];
                    for (; $i >= 0; $i--) {
                        array_unshift($block, trim($lines[$i]));
                        if (str_contains($lines[$i], '/*')) {
                            break;
                        }
                    }
                    $collected = array_merge($block, $collected);
                }
                break;
            }
            $text = implode("\n", $collected);
        }

        $text = $this->cleanComment($text);

        // Dòng mô tả endpoint trong docblock của class: "POST /api/admin/rooms/{id}/block — ..."
        $classDoc = $this->cleanComment($ref->getDeclaringClass()->getDocComment() ?: '');
        $endpoint = '/' . $route->uri();
        foreach (preg_split('/\n/u', $classDoc) as $line) {
            foreach (array_diff($route->methods(), ['HEAD']) as $m) {
                if (preg_match('#\b' . $m . '\s+' . preg_quote($endpoint, '#') . '\b\s*[—:-]*\s*(.+)$#u', $line, $mm) && ! str_contains($text, trim($mm[1]))) {
                    $text = trim(trim($mm[1]) . "\n" . $text);
                }
            }
        }

        return $text;
    }

    private function cleanComment(string $raw): string
    {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8'); // bỏ byte không hợp lệ để regex /u không trả null
        $out = [];
        foreach (preg_split('/\R/u', $raw) as $l) {
            $l = trim($l);
            $l = (string) preg_replace('#^(/\*\*?|\*/|\*|//+)\s?#u', '', $l);
            $l = (string) preg_replace('#\*/$#u', '', $l);
            $l = rtrim($l);
            if ($l === '' || preg_match('/^@(param|return|throws|var|see)\b/u', $l) || preg_match('/^[─━=\-]{3,}/u', $l)) {
                $out[] = '';
                continue;
            }
            if (preg_match('#^(GET|POST|PUT|PATCH|DELETE)(\|[A-Z]+)*\s+/\S+\s*$#u', $l)) {
                continue; // dòng chỉ ghi lại endpoint
            }
            $out[] = $l;
        }

        // Gộp các dòng xuống dòng cứng thành đoạn; giữ gạch đầu dòng.
        $paras = [];
        $cur = '';
        foreach ($out as $l) {
            if ($l === '') {
                if ($cur !== '') {
                    $paras[] = $cur;
                    $cur = '';
                }
                continue;
            }
            if (preg_match('/^([-•]|\d+[.)])\s/u', $l)) {
                if ($cur !== '') {
                    $paras[] = $cur;
                }
                $cur = '- ' . preg_replace('/^([-•]|\d+[.)])\s+/u', '', $l);
                continue;
            }
            $cur = $cur === '' ? $l : $cur . ' ' . $l;
        }
        if ($cur !== '') {
            $paras[] = $cur;
        }

        return trim(implode("\n", array_map(fn ($p) => (string) preg_replace('/\s{2,}/u', ' ', $p), $paras)));
    }

    // ── Luật validate ───────────────────────────────────────────────────────────────────────────────

    private function collectRules(Node $method, Node\Stmt\ClassLike $class, array &$rules): void
    {
        $calls = $this->finder->find($method, function (Node $n) {
            if ($n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'validate') {
                return true;
            }

            return $n instanceof Node\Expr\StaticCall && $n->class instanceof Node\Name && str_ends_with($n->class->toString(), 'Validator')
                && $n->name instanceof Node\Identifier && $n->name->toString() === 'make';
        });

        foreach ($calls as $call) {
            $args = $call->getArgs();
            // $this->validate($request, [...]) / Validator::make($data, [...]) → luật ở tham số thứ 2
            $isTwoArg = $call instanceof Node\Expr\StaticCall || ($call->var instanceof Node\Expr\Variable && $call->var->name === 'this');
            $arg = $args[$isTwoArg ? 1 : 0] ?? null;
            if (! $arg) {
                continue;
            }
            foreach ($this->resolveRuleArray($arg->value, $method, $class, 0) as $k => $v) {
                $rules[$k] = array_values(array_unique(array_merge($rules[$k] ?? [], $v)));
            }
        }
    }

    private function resolveRuleArray(Node\Expr $expr, Node $method, Node\Stmt\ClassLike $class, int $depth): array
    {
        if ($depth > 4) {
            return [];
        }
        if ($expr instanceof Node\Expr\Array_) {
            return $this->rulesFromArray($expr, $method, $class, $depth);
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $result = [];
            foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\Assign && $n->var instanceof Node\Expr\Variable && $n->var->name === $expr->name) as $assign) {
                $result += $this->resolveRuleArray($assign->expr, $method, $class, $depth + 1);
            }
            // $rules['x'] = '...';
            foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\Assign && $n->var instanceof Node\Expr\ArrayDimFetch
                && $n->var->var instanceof Node\Expr\Variable && $n->var->var->name === $expr->name && $n->var->dim instanceof Node\Scalar\String_) as $assign) {
                $result[$assign->var->dim->value] = $this->ruleList($assign->expr);
            }

            return $result;
        }
        if ($expr instanceof Node\Expr\MethodCall && $expr->var instanceof Node\Expr\Variable && $expr->var->name === 'this' && $expr->name instanceof Node\Identifier) {
            return $this->rulesFromMethod($class, $expr->name->toString(), $depth);
        }
        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier && $expr->class instanceof Node\Name && in_array($expr->class->toString(), ['self', 'static'], true)) {
            return $this->rulesFromMethod($class, $expr->name->toString(), $depth);
        }
        if ($expr instanceof Node\Expr\ClassConstFetch && $expr->name instanceof Node\Identifier) {
            foreach ($class->getConstants() as $const) {
                foreach ($const->consts as $c) {
                    if ($c->name->toString() === $expr->name->toString() && $c->value instanceof Node\Expr\Array_) {
                        return $this->rulesFromArray($c->value, $method, $class, $depth);
                    }
                }
            }
        }
        if ($expr instanceof Node\Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->toString() === 'array_merge') {
            $result = [];
            foreach ($expr->getArgs() as $a) {
                $result = array_merge($result, $this->resolveRuleArray($a->value, $method, $class, $depth + 1));
            }

            return $result;
        }
        if ($expr instanceof Node\Expr\BinaryOp\Plus) {
            return $this->resolveRuleArray($expr->left, $method, $class, $depth + 1) + $this->resolveRuleArray($expr->right, $method, $class, $depth + 1);
        }

        return [];
    }

    private function rulesFromMethod(Node\Stmt\ClassLike $class, string $name, int $depth): array
    {
        $m = $this->classMethod($class, $name);
        if (! $m) {
            return [];
        }
        // Chỉ lấy return của CHÍNH method (bỏ qua return bên trong closure / arrow fn khai báo trong method)
        $result = [];
        foreach ($this->ownReturns($m->stmts ?? []) as $ret) {
            $result += $this->resolveRuleArray($ret->expr, $m, $class, $depth + 1);
        }

        return $result;
    }

    /** @return Node\Stmt\Return_[] */
    private function ownReturns(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $s) {
            if ($s instanceof Node\Stmt\Return_ && $s->expr !== null) {
                $out[] = $s;
                continue;
            }
            if ($s instanceof Node\Stmt\Function_ || $s instanceof Node\Stmt\ClassLike) {
                continue;
            }
            // Khối lệnh lồng (if/else/elseif/switch/try/foreach...) — biểu thức closure không phải Stmt nên tự bị bỏ qua
            if (property_exists($s, 'stmts') && is_array($s->stmts)) {
                $out = array_merge($out, $this->ownReturns($s->stmts));
            }
            foreach (['else', 'elseifs', 'cases', 'catches', 'finally'] as $prop) {
                if (! property_exists($s, $prop) || $s->$prop === null) {
                    continue;
                }
                foreach (is_array($s->$prop) ? $s->$prop : [$s->$prop] as $c) {
                    $out = array_merge($out, $this->ownReturns($c->stmts ?? []));
                }
            }
        }

        return $out;
    }

    private function rulesFromArray(Node\Expr\Array_ $arr, Node $method, Node\Stmt\ClassLike $class, int $depth): array
    {
        $rules = [];
        foreach ($arr->items as $item) {
            if (! $item) {
                continue;
            }
            if ($item->unpack) {
                $rules += $this->resolveRuleArray($item->value, $method, $class, $depth + 1);
                continue;
            }
            $key = $this->stringValue($item->key);
            if ($key === null) {
                continue;
            }
            $rules[$key] = $this->ruleList($item->value);
        }

        return $rules;
    }

    private function ruleList(Node\Expr $value): array
    {
        $s = $this->stringValue($value);
        if ($s !== null) {
            return array_values(array_filter(str_starts_with($s, 'regex:') ? [$s] : explode('|', $s), 'strlen'));
        }
        $list = [];
        if ($value instanceof Node\Expr\Array_) {
            foreach ($value->items as $it) {
                if (! $it) {
                    continue;
                }
                $v = $it->value;
                if (($s = $this->stringValue($v)) !== null) {
                    // Dạng mảng: mỗi phần tử là 1 luật — không tách theo '|' (regex có thể chứa '|')
                    $list = array_merge($list, str_starts_with($s, 'regex:') || str_starts_with($s, 'not_regex:') ? [$s] : explode('|', $s));
                } elseif ($v instanceof Node\Expr\Ternary) {
                    // [$x ? 'required' : 'sometimes', ...] → lấy nhánh đầu
                    if (($s = $this->stringValue($v->if)) !== null) {
                        $list[] = $s;
                    }
                } elseif ($v instanceof Node\Expr\StaticCall && $v->name instanceof Node\Identifier && $v->class instanceof Node\Name && str_ends_with($v->class->toString(), 'Rule')) {
                    $fn = $v->name->toString();
                    if ($fn === 'in' && ($a = $v->getArgs()[0] ?? null)) {
                        $vals = $this->literalList($a->value);
                        if ($vals) {
                            $list[] = 'in:' . implode(',', $vals);
                        }
                    } elseif ($fn === 'exists' && ($a = $v->getArgs()[0] ?? null) && ($t = $this->stringValue($a->value)) !== null) {
                        $list[] = 'exists:' . $t;
                    } elseif ($fn === 'enum') {
                        $list[] = 'enum';
                    } elseif ($fn === 'file' || $fn === 'imageFile') {
                        $list[] = 'file';
                    }
                } elseif ($v instanceof Node\Expr\New_ && $v->class instanceof Node\Name && str_ends_with($v->class->toString(), 'Enum')) {
                    $list[] = 'enum';
                } elseif ($v instanceof Node\Expr\MethodCall && $v->name instanceof Node\Identifier) {
                    // File::types([...])->max(...) / Rule::exists(...)->where(...)
                    $root = $v;
                    while ($root instanceof Node\Expr\MethodCall) {
                        $root = $root->var;
                    }
                    if ($root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name && str_ends_with($root->class->toString(), 'File')) {
                        $list[] = 'file';
                    } elseif ($root instanceof Node\Expr\StaticCall && $root->class instanceof Node\Name && str_ends_with($root->class->toString(), 'Rule')
                        && $root->name instanceof Node\Identifier && $root->name->toString() === 'exists' && ($t = $this->stringValue($root->getArgs()[0]->value ?? null)) !== null) {
                        $list[] = 'exists:' . $t;
                    }
                }
            }
        }

        return array_values(array_filter(array_map('trim', $list), 'strlen'));
    }

    private function stringValue(?Node $n): ?string
    {
        if ($n instanceof Node\Scalar\String_) {
            return $n->value;
        }
        if ($n instanceof Node\Expr\BinaryOp\Concat) {
            $l = $this->stringValue($n->left);
            $r = $this->stringValue($n->right);
            if ($l !== null && $r !== null) {
                return $l . $r;
            }
            // 'in:' . implode(',', [...]) → giữ phần cố định
            if ($l !== null && $n->right instanceof Node\Expr\FuncCall && $n->right->name instanceof Node\Name && $n->right->name->toString() === 'implode') {
                $vals = $this->literalList($n->right->getArgs()[1]->value ?? null);

                return $vals ? $l . implode(',', $vals) : rtrim($l, ':,');
            }
            // ($x ? 'sometimes|' : '') . 'required|date' → phần cố định bên phải
            if ($l === null && $r !== null) {
                return ltrim($r, '|');
            }

            return $l !== null ? rtrim($l, ':,') : null;
        }

        return null;
    }

    private function literalList(?Node $n): array
    {
        if (! $n instanceof Node\Expr\Array_) {
            return [];
        }
        $vals = [];
        foreach ($n->items as $it) {
            $v = $it?->value;
            if ($v instanceof Node\Scalar\String_ || $v instanceof Node\Scalar\Int_) {
                $vals[] = (string) $v->value;
            }
        }

        return $vals;
    }

    // ── Quyền ───────────────────────────────────────────────────────────────────────────────────────

    private function collectAuth(Node $method, array &$info): void
    {
        foreach ($this->finder->find($method, fn (Node $n) => ($n instanceof Node\Expr\MethodCall || $n instanceof Node\Expr\StaticCall) && $n->name instanceof Node\Identifier) as $call) {
            $name = $call->name->toString();
            // ->can('quyền') / hasPermissionTo / $this->hasPermission($request, 'quyền') / hasAnyPermission([...]) — quyền ở tham số bất kỳ
            if (preg_match('/^(can|cannot|hasPermissionTo|hasPermission|hasAnyPermission|authorize|checkPermission|requirePermission)$/', $name)) {
                foreach ($call->getArgs() as $a) {
                    foreach ($a->value instanceof Node\Expr\Array_ ? $this->literalList($a->value) : [$this->stringValue($a->value)] as $perm) {
                        if ($perm && preg_match('/^[a-z0-9_:.]+$/i', $perm) && str_contains($perm, '_')) {
                            $info['perms'][$perm] = true;
                        }
                    }
                }
            }
            if (preg_match('/^(authorizeSuper(Admin)?|ensureSuperAdmin|requireSuperAdmin|onlySuperAdmin)$/', $name)) {
                $info['super'] = true;
            }
        }

        // Chỉ Super Admin khi điều kiện BẮT BUỘC Super Admin: abort_unless(isSuperAdmin() [&& ...]) / abort_if(! isSuperAdmin())
        // / if (! isSuperAdmin()) { 403 }. "isSuperAdmin() || partner_id === ..." = Super Admin HOẶC chính đối tác đó → không phải chỉ Super Admin.
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && in_array($n->name->toString(), ['abort_unless', 'abort_if'], true)) as $call) {
            $cond = $call->getArgs()[0]->value ?? null;
            if (! $cond) {
                continue;
            }
            $allowCond = $call->name->toString() === 'abort_unless' ? $cond : ($cond instanceof Node\Expr\BooleanNot ? $cond->expr : null);
            if ($allowCond && $this->requiresSuperAdmin($allowCond)) {
                $info['super'] = true;
            } elseif ($allowCond && $this->mentionsSuperAdmin($allowCond) && $this->mentionsOwnPartner($allowCond)) {
                $info['owner'] = true;
            }
        }
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Stmt\If_) as $if) {
            if ($if->cond instanceof Node\Expr\BooleanNot && $this->requiresSuperAdmin($if->cond->expr) && $this->stmtsDeny($if->stmts)) {
                $info['super'] = true;
            }
        }
    }

    private function requiresSuperAdmin(Node $cond): bool
    {
        if ($cond instanceof Node\Expr\MethodCall && $cond->name instanceof Node\Identifier && $cond->name->toString() === 'isSuperAdmin') {
            return true;
        }
        if ($cond instanceof Node\Expr\BinaryOp\BooleanAnd || $cond instanceof Node\Expr\BinaryOp\LogicalAnd) {
            return $this->requiresSuperAdmin($cond->left) || $this->requiresSuperAdmin($cond->right);
        }

        return false;
    }

    private function mentionsOwnPartner(Node $cond): bool
    {
        return (bool) $this->finder->findFirst($cond, fn (Node $x) => $x instanceof Node\Expr\PropertyFetch && $x->name instanceof Node\Identifier && $x->name->toString() === 'partner_id');
    }

    private function mentionsSuperAdmin(Node $n): bool
    {
        return (bool) $this->finder->findFirst($n, fn (Node $x) => $x instanceof Node\Expr\MethodCall && $x->name instanceof Node\Identifier && $x->name->toString() === 'isSuperAdmin');
    }

    private function stmtsDeny(array $stmts): bool
    {
        return (bool) $this->finder->findFirst($stmts, fn (Node $n) => $n instanceof Node\Scalar\Int_ && $n->value === 403);
    }

    // ── Response ────────────────────────────────────────────────────────────────────────────────────

    private function collectResponses(Node $method, array &$info): void
    {
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier
            && in_array($n->name->toString(), ['json', 'stream', 'download', 'streamDownload', 'file', 'noContent'], true)
            && $n->var instanceof Node\Expr\FuncCall && $n->var->name instanceof Node\Name && $n->var->name->toString() === 'response') as $call) {
            $kind = $call->name->toString();
            if ($kind !== 'json') {
                $info['stream'] = $kind === 'noContent' ? 'noContent' : 'file';
                continue;
            }
            $args = $call->getArgs();
            $status = isset($args[1]) ? $this->statusValue($args[1]->value) : 200;
            if ($status === null) {
                continue;
            }
            $body = isset($args[0]) ? $this->exampleFrom($args[0]->value) : null;
            $this->addResponse($info, $status, $body);
        }

        $this->collectErrors($method, $info);

        // Trả thẳng Resource / phân trang
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Stmt\Return_ && $n->expr !== null) as $ret) {
            $e = $ret->expr;
            if ($e instanceof Node\Expr\New_ && $e->class instanceof Node\Name && str_ends_with($e->class->toString(), 'Resource')) {
                $this->addResponse($info, 200, ['data' => new stdClass()]);
            } elseif ($e instanceof Node\Expr\StaticCall && $e->name instanceof Node\Identifier && $e->name->toString() === 'collection') {
                $this->addResponse($info, 200, ['data' => []]);
            } elseif ($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier && in_array($e->name->toString(), ['paginate', 'simplePaginate'], true)) {
                $this->addResponse($info, 200, ['current_page' => 1, 'data' => [], 'per_page' => 15, 'total' => 0, 'last_page' => 1]);
            }
        }
    }

    private function collectErrors(Node $method, array &$info): void
    {
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\FuncCall && $n->name instanceof Node\Name && in_array($n->name->toString(), ['abort', 'abort_if', 'abort_unless'], true)) as $call) {
            $args = $call->getArgs();
            $off = $call->name->toString() === 'abort' ? 0 : 1;
            $status = isset($args[$off]) ? $this->statusValue($args[$off]->value) : null;
            if ($status && $status >= 400) {
                $msg = isset($args[$off + 1]) ? $this->stringValue($args[$off + 1]->value) : null;
                $info['errors'][$status][$msg ?? ''] = true;
            }
        }
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\StaticCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'withMessages'
            && $n->class instanceof Node\Name && str_ends_with($n->class->toString(), 'ValidationException')) as $call) {
            $arr = $call->getArgs()[0]->value ?? null;
            if ($arr instanceof Node\Expr\Array_) {
                foreach ($arr->items as $it) {
                    $field = $this->stringValue($it?->key);
                    $msgs = $it?->value instanceof Node\Expr\Array_ ? $this->literalStrings($it->value) : array_filter([$this->stringValue($it?->value)]);
                    if ($field !== null) {
                        foreach ($msgs ?: [''] as $m) {
                            $info['errors'][422][$m] = ['field' => $field];
                        }
                    }
                }
            }
        }
        // response()->json([... 'message' => '...'], 4xx) trong method phụ
        foreach ($this->finder->find($method, fn (Node $n) => $n instanceof Node\Expr\MethodCall && $n->name instanceof Node\Identifier && $n->name->toString() === 'json'
            && $n->var instanceof Node\Expr\FuncCall && $n->var->name instanceof Node\Name && $n->var->name->toString() === 'response') as $call) {
            $args = $call->getArgs();
            $status = isset($args[1]) ? $this->statusValue($args[1]->value) : 200;
            if ($status !== null && $status >= 400 && isset($args[0]) && $args[0]->value instanceof Node\Expr\Array_) {
                foreach ($args[0]->value->items as $it) {
                    if ($this->stringValue($it?->key) === 'message' && ($m = $this->stringValue($it->value)) !== null) {
                        $info['errors'][$status][$m] ??= true;
                    }
                }
            }
        }
    }

    private function literalStrings(Node\Expr\Array_ $arr): array
    {
        $out = [];
        foreach ($arr->items as $it) {
            if (($s = $this->stringValue($it?->value)) !== null) {
                $out[] = $s;
            }
        }

        return $out;
    }

    private function statusValue(Node $n): ?int
    {
        if ($n instanceof Node\Scalar\Int_) {
            return $n->value;
        }
        if ($n instanceof Node\Expr\ClassConstFetch && $n->name instanceof Node\Identifier) {
            $map = ['HTTP_OK' => 200, 'HTTP_CREATED' => 201, 'HTTP_ACCEPTED' => 202, 'HTTP_NO_CONTENT' => 204, 'HTTP_BAD_REQUEST' => 400, 'HTTP_UNAUTHORIZED' => 401,
                'HTTP_PAYMENT_REQUIRED' => 402, 'HTTP_FORBIDDEN' => 403, 'HTTP_NOT_FOUND' => 404, 'HTTP_CONFLICT' => 409, 'HTTP_GONE' => 410,
                'HTTP_UNPROCESSABLE_ENTITY' => 422, 'HTTP_TOO_MANY_REQUESTS' => 429, 'HTTP_INTERNAL_SERVER_ERROR' => 500, 'HTTP_SERVICE_UNAVAILABLE' => 503];

            return $map[$n->name->toString()] ?? null;
        }

        return null; // mã trạng thái tính lúc chạy
    }

    private function addResponse(array &$info, int $status, $body): void
    {
        if ($status >= 400) {
            $msg = is_array($body) && isset($body['message']) && is_string($body['message']) && $body['message'] !== 'Thao tác thành công.' ? $body['message'] : '';
            $info['errors'][$status][$msg] ??= true;

            return;
        }
        // Giữ ví dụ giàu thông tin nhất cho mỗi mã thành công
        if (! isset($info['responses'][$status]) || $this->weight($body) > $this->weight($info['responses'][$status])) {
            $info['responses'][$status] = $body;
        }
    }

    private function weight($v): int
    {
        if (! is_array($v)) {
            return 0;
        }
        $w = count($v);
        foreach ($v as $x) {
            $w += $this->weight($x);
        }

        return $w;
    }

    /** Giá trị ví dụ dựng từ biểu thức PHP: hằng giữ nguyên, phần tính lúc chạy thay bằng giá trị minh hoạ theo tên khoá. */
    private function exampleFrom(Node\Expr $e, string $key = '', int $depth = 0)
    {
        if ($depth > 6) {
            return null;
        }
        if ($e instanceof Node\Scalar\String_ || $e instanceof Node\Scalar\Int_ || $e instanceof Node\Scalar\Float_) {
            return $e->value;
        }
        if ($e instanceof Node\Expr\ConstFetch) {
            return match (strtolower($e->name->toString())) { 'true' => true, 'false' => false, default => null };
        }
        if ($e instanceof Node\Expr\Array_) {
            $isList = true;
            $out = [];
            foreach ($e->items as $it) {
                if (! $it || $it->unpack) {
                    continue;
                }
                $k = $this->stringValue($it->key);
                if ($k === null && $it->key !== null) {
                    continue;
                }
                if ($k !== null) {
                    $isList = false;
                    $out[$k] = $this->exampleFrom($it->value, $k, $depth + 1);
                } else {
                    $out[] = $this->exampleFrom($it->value, $key, $depth + 1);
                }
            }

            return $isList ? array_slice($out, 0, 2) : $out;
        }
        if ($e instanceof Node\Expr\BinaryOp\Concat || $e instanceof Node\Scalar\InterpolatedString) {
            return $this->guess($key, 'string');
        }
        if ($e instanceof Node\Expr\Cast\Int_) {
            return $this->guess($key, 'integer');
        }
        if ($e instanceof Node\Expr\Cast\Bool_ || $e instanceof Node\Expr\BooleanNot || $e instanceof Node\Expr\BinaryOp\Identical || $e instanceof Node\Expr\BinaryOp\NotIdentical
            || $e instanceof Node\Expr\BinaryOp\BooleanAnd || $e instanceof Node\Expr\BinaryOp\BooleanOr || $e instanceof Node\Expr\Isset_) {
            return true;
        }
        if ($e instanceof Node\Expr\Cast\Double) {
            return $this->guess($key, 'number');
        }
        if ($e instanceof Node\Expr\Ternary && $e->if) {
            return $this->exampleFrom($e->if, $key, $depth + 1);
        }
        if ($e instanceof Node\Expr\BinaryOp\Coalesce) {
            return $this->exampleFrom($e->left, $key, $depth + 1) ?? $this->exampleFrom($e->right, $key, $depth + 1);
        }
        if ($e instanceof Node\Expr\FuncCall && $e->name instanceof Node\Name && in_array($e->name->toString(), ['__', 'trans'], true)) {
            return $this->guess($key, 'string');
        }
        if ($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier && in_array($e->name->toString(), ['toIso8601String', 'toDateTimeString', 'toDateString', 'format'], true)) {
            return '2026-11-04T10:00:00+07:00';
        }
        if ($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier && in_array($e->name->toString(), ['count', 'sum', 'total'], true)) {
            return 0;
        }
        if ($e instanceof Node\Expr\MethodCall && $e->name instanceof Node\Identifier && in_array($e->name->toString(), ['all', 'values', 'toArray', 'get', 'map', 'pluck'], true)) {
            return $this->guess($key, 'array');
        }

        return $this->guess($key, null);
    }

    private function guess(string $key, ?string $type)
    {
        $k = strtolower($key);
        if ($type === 'array' || (in_array($k, ['data', 'items', 'rows', 'list', 'results', 'errors'], true) && $type === null)) {
            return $k === 'data' ? new stdClass() : [];
        }
        if ($k === 'message') {
            return 'Thao tác thành công.';
        }
        if ($type === 'integer' || preg_match('/(^id$|_id$|count|total|amount|price|quantity|_count$|^page$|per_page|last_page|fee|balance|nights|points|stars|rating)/', $k)) {
            return preg_match('/^id$|_id$/', $k) ? 1 : 0;
        }
        if ($type === 'number') {
            return 0.0;
        }
        if (preg_match('/(_at|_date|date|time)$/', $k)) {
            return '2026-11-04T10:00:00+07:00';
        }
        if (preg_match('/^(is_|has_|can_)|enabled|active|success|ok$/', $k)) {
            return true;
        }
        if (preg_match('/(url|link)$/', $k)) {
            return 'https://365home.vn/...';
        }
        if (str_contains($k, 'phone')) {
            return '0901234567';
        }
        if (str_contains($k, 'email')) {
            return 'khach@example.com';
        }
        if (preg_match('/(code|token)$/', $k)) {
            return 'ABC123';
        }
        if (preg_match('/(name|title|label)$/', $k)) {
            return 'Tên mẫu';
        }
        if ($k === 'status') {
            return 'active';
        }

        return $type === 'string' ? 'string' : null;
    }

    // ── Dựng operation ──────────────────────────────────────────────────────────────────────────────

    private function operation(Route $route, string $method, string $path, string $account, string $module, array $mw, array $info, string $tag): array
    {
        $summaryText = $this->summary($method, $path, $info['method']);
        $moduleName = $this->cfg['modules'][sprintf('%02d', (int) $module)][0];
        $needsAuth = $account !== 'SYSTEM' && ($this->hasMw($mw, 'Authenticate') || $account === 'ADMIN');

        // Nhãn tài khoản dùng được
        $perms = array_keys($info['perms']);
        if ($account === 'ADMIN') {
            if ($route->uri() === 'api/admin/login') {
                $allowed = ['CÔNG_KHAI'];
            } elseif ($info['super']) {
                $allowed = ['SUPER_ADMIN'];
            } elseif ($perms) {
                $allowed = ['SUPER_ADMIN', 'TÀI_KHOẢN_CÓ_QUYỀN'];
            } else {
                $allowed = ['SUPER_ADMIN', 'MỌI_TÀI_KHOẢN_ADMIN'];
            }
        } elseif ($needsAuth) {
            $allowed = [self::ACCOUNT_BADGE[$account]];
        } else {
            $allowed = [$account === 'SYSTEM' ? 'TÍCH_HỢP' : 'CÔNG_KHAI'];
        }

        $partnerType = null;
        $throttle = [];
        foreach ($mw as $m) {
            if (preg_match('/EnsurePartnerType:(\w+)/', $m, $mm)) {
                $partnerType = $mm[1];
            }
            if (preg_match('/ThrottleRequests:([\w,-]+)/', $m, $mm) && $mm[1] !== 'api') { // 'api' = nhóm mặc định của mọi route /api
                $throttle[] = $mm[1];
            }
        }

        // Tham số + body
        $pathParams = [];
        preg_match_all('/\{(\w+)\}/', $path, $pm);
        foreach ($pm[1] as $name) {
            $pathParams[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => $this->fieldLabel($name, true)];
        }
        $rules = $info['rules'];
        $query = [];
        $body = null;
        if ($rules) {
            if ($method === 'GET') {
                foreach ($rules as $field => $r) {
                    if (str_contains((string) $field, '.')) {
                        continue;
                    }
                    $schema = $this->ruleSchema((string) $field, $r);
                    if ($schema['file']) {
                        continue;
                    }
                    $query[] = ['name' => (string) $field, 'in' => 'query', 'required' => in_array('required', $r, true), 'schema' => $schema['schema'], 'description' => $schema['description']];
                }
            } else {
                $body = $this->requestBody($rules);
            }
        }

        $parameters = array_merge($pathParams, $query);
        if ($account === 'ADMIN' && $route->uri() !== 'api/admin/login') {
            $parameters[] = ['$ref' => '#/components/parameters/PartnerIdHeader'];
        }

        // Responses
        $responses = [];
        $okCodes = array_keys($info['responses']);
        sort($okCodes);
        if ($info['stream'] === 'file') {
            $responses['200'] = ['description' => 'Thành công — trả về file (tải xuống / xem trực tiếp)', 'content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
        } elseif ($info['stream'] === 'noContent' && ! $okCodes) {
            $responses['204'] = ['description' => 'Thành công, không có nội dung trả về'];
        }
        foreach ($okCodes as $code) {
            $ex = $info['responses'][$code];
            $resp = ['description' => $code === 201 ? 'Đã tạo' : 'Thành công'];
            if ($ex !== null) {
                $resp['content'] = ['application/json' => ['schema' => $this->schemaOf($ex), 'example' => $ex]];
            }
            $responses[(string) $code] = $resp;
        }
        if (! $responses) {
            $responses['200'] = ['description' => 'Thành công (cấu trúc dữ liệu trả về tính lúc chạy — xem mô tả nghiệp vụ)'];
        }
        if ($needsAuth && $allowed !== ['CÔNG_KHAI']) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthenticated'];
        }
        if ($account === 'ADMIN' && $allowed !== ['CÔNG_KHAI']) {
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }
        $errors = $info['errors'];
        if ($rules || isset($errors[422])) {
            $errors[422] = $errors[422] ?? [];
        }
        ksort($errors);
        foreach ($errors as $code => $msgs) {
            $code = (int) $code;
            if ($code === 401 && isset($responses['401'])) {
                continue;
            }
            $messages = array_values(array_filter(array_map('strval', array_keys($msgs)), fn ($m) => $m !== ''));
            if ($code === 422) {
                // Lỗi gắn với trường (ValidationException::withMessages) làm ví dụ; không có thì dùng lỗi của trường bắt buộc đầu tiên.
                // Thông báo abort(422, '...') không gắn trường → liệt kê trong mô tả.
                $fieldErrors = [];
                $plain = [];
                foreach ($msgs as $m => $meta) {
                    $m = (string) $m;
                    if ($m === '') {
                        continue;
                    }
                    if (is_array($meta) && isset($meta['field'])) {
                        $fieldErrors[$meta['field']][] = $m;
                    } else {
                        $plain[] = $m;
                    }
                }
                $firstRequired = null;
                foreach ($rules as $f => $r) {
                    if (in_array('required', $r, true) && ! str_contains((string) $f, '*')) {
                        $firstRequired = (string) $f;
                        break;
                    }
                }
                $firstRequired ??= $rules ? (string) array_key_first($rules) : null;
                if ($fieldErrors) {
                    $example = ['message' => reset($fieldErrors)[0], 'errors' => $fieldErrors];
                } elseif ($firstRequired !== null) {
                    $example = ['message' => "Trường {$firstRequired} là bắt buộc.", 'errors' => [$firstRequired => ["Trường {$firstRequired} là bắt buộc."]]];
                } else {
                    $example = ['message' => $plain[0] ?? 'Dữ liệu không hợp lệ.'];
                }
                $desc = 'Dữ liệu không hợp lệ';
                if ($plain) {
                    $desc .= '. Lỗi nghiệp vụ có thể gặp: ' . implode(' | ', array_map(fn ($m) => '"' . $m . '"', array_slice($plain, 0, 8)));
                }
                $responses['422'] = ['description' => $desc, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError'], 'example' => $example]]];
                continue;
            }
            if ($code === 403 && isset($responses['403']) && ! $messages) {
                continue;
            }
            $desc = $this->statusLabel($code);
            if (count($messages) > 1) {
                $desc .= '. Các thông báo có thể gặp: ' . implode(' | ', array_map(fn ($m) => '"' . $m . '"', array_slice($messages, 0, 8)));
            }
            $responses[(string) $code] = ['description' => $desc, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message'], 'example' => ['message' => $messages[0] ?? $this->statusLabel($code) . '.']]]];
        }
        uksort($responses, fn ($a, $b) => (int) $a <=> (int) $b);

        $op = [
            'tags' => [$tag],
            'operationId' => $this->operationId($method, $path),
            'summary' => '[' . implode('] [', $allowed) . '] ' . $summaryText,
            'description' => $this->description($method, $path, $moduleName, $summaryText, $info, $account, $allowed, $perms, $partnerType, $throttle, $pathParams, $query, $body, $responses, $needsAuth),
        ];
        $op['security'] = $this->security($account, $allowed, $needsAuth);
        if ($perms && $account === 'ADMIN') {
            $op['x-permission'] = count($perms) === 1 ? $perms[0] : $perms;
        }
        if ($partnerType) {
            $op['x-partner-type'] = $partnerType;
        }
        $op['x-allowed-accounts'] = $allowed;
        if ($info['class']) {
            $op['x-controller'] = class_basename($info['class']) . '@' . $info['method'];
        }
        if ($parameters) {
            $op['parameters'] = $parameters;
        }
        if ($body) {
            $op['requestBody'] = $body;
        }
        $op['responses'] = $responses;

        return $op;
    }

    private function security(string $account, array $allowed, bool $needsAuth): array
    {
        if (in_array('CÔNG_KHAI', $allowed, true) || ! $needsAuth) {
            return [];
        }

        return match ($account) {
            'ADMIN' => $allowed === ['SUPER_ADMIN'] ? [['superAdminAuth' => []]] : [['partnerAdminAuth' => []], ['superAdminAuth' => []]],
            'TENANT' => [['tenantAuth' => []]],
            default => [['clientAuth' => []]],
        };
    }

    private function statusLabel(int $code): string
    {
        return [400 => 'Yêu cầu không hợp lệ', 401 => 'Chưa đăng nhập / sai thông tin đăng nhập', 402 => 'Gói dịch vụ đã hết hạn', 403 => 'Không có quyền thực hiện',
            404 => 'Không tìm thấy dữ liệu', 409 => 'Xung đột dữ liệu', 410 => 'Liên kết đã hết hạn', 422 => 'Dữ liệu không hợp lệ', 423 => 'Đang bị khoá',
            429 => 'Gửi quá nhiều yêu cầu', 500 => 'Lỗi hệ thống', 502 => 'Lỗi từ dịch vụ bên ngoài', 503 => 'Dịch vụ tạm thời không khả dụng'][$code] ?? 'Lỗi ' . $code;
    }

    private function description(string $method, string $path, string $moduleName, string $summary, array $info, string $account, array $allowed, array $perms,
        ?string $partnerType, array $throttle, array $pathParams, array $query, ?array $body, array $responses, bool $needsAuth): string
    {
        $purpose = match ($method) {
            'GET' => 'dùng để tra cứu hoặc lấy dữ liệu',
            'POST' => 'dùng để tạo dữ liệu hoặc thực hiện một thao tác nghiệp vụ',
            'PUT', 'PATCH' => 'dùng để cập nhật dữ liệu',
            'DELETE' => 'dùng để xoá hoặc huỷ dữ liệu',
            default => 'thực hiện thao tác nghiệp vụ',
        };
        $lower = mb_strtolower(mb_substr($summary, 0, 1)) . mb_substr($summary, 1);
        $parts = [];
        $parts[] = "### Mục đích\nEndpoint này dùng để **{$lower}** trong module **{$moduleName}**. Đây là thao tác `{$method}` {$purpose}.";
        $parts[] = "### Endpoint\n`{$method} {$path}`";
        $parts[] = "### Mô tả nghiệp vụ\n" . ($info['comment'] !== '' ? $info['comment'] : $summary . '.');

        $auth = [];
        if ($account === 'ADMIN') {
            if ($allowed === ['CÔNG_KHAI']) {
                $auth[] = 'Không cần token (endpoint lấy token đăng nhập quản trị).';
            } elseif ($allowed === ['SUPER_ADMIN']) {
                $auth[] = '**Chỉ Super Admin.** Tài khoản đối tác / nhân viên gọi sẽ nhận `403`.';
            } elseif ($perms) {
                $auth[] = '**Quyền Shield cần có:** `' . implode('`, `', $perms) . '`. Super Admin luôn được phép; tài khoản đối tác/nhân viên phải được cấp quyền này trong vai trò.';
            } else {
                $auth[] = 'Cần Bearer token quản trị (`POST /admin/login`). Mọi tài khoản quản trị đều gọi được; dữ liệu tự giới hạn theo đối tác của tài khoản.';
            }
            if ($info['owner'] && $allowed !== ['SUPER_ADMIN']) {
                $auth[] = 'Chỉ Super Admin hoặc tài khoản thuộc **chính đối tác** trong đường dẫn; tài khoản của đối tác khác nhận `403`.';
            }
            if ($allowed !== ['CÔNG_KHAI']) {
                $auth[] = 'Tài khoản đối tác chỉ thao tác dữ liệu thuộc đối tác của mình. Super Admin có thể gửi `X-Partner-Id` để giới hạn ngữ cảnh vào một đối tác.';
            }
            if ($partnerType) {
                $auth[] = 'Chỉ dùng cho đối tác loại **' . ($partnerType === 'minihouse' ? 'MiniHouse' : 'Homestay') . '**.';
            }
        } elseif ($account === 'TENANT') {
            $auth[] = $needsAuth ? 'Cần Bearer token của khách thuê MiniHouse (`POST /minihouse/portal/otp/verify` hoặc `/minihouse/portal/login/password`).' : 'Không cần đăng nhập (bước lấy token của cổng khách thuê).';
        } elseif ($account === 'SYSTEM') {
            $auth[] = 'Dành cho hệ thống bên ngoài gọi vào (cổng thanh toán, khoá cửa, ứng dụng thẻ). Xác thực bằng chữ ký / token riêng của từng tích hợp — xem mô tả nghiệp vụ.';
        } else {
            $auth[] = $needsAuth ? 'Cần Bearer token của khách hàng.' : 'Không cần đăng nhập (công khai).';
        }
        if ($throttle) {
            $auth[] = 'Giới hạn tần suất: `' . implode('`, `', $throttle) . '`.';
        }
        $parts[] = "### Tài khoản và phân quyền\n" . implode("\n\n", $auth);

        $inputs = [];
        if ($pathParams) {
            $inputs[] = 'tham số đường dẫn `' . implode('`, `', array_column($pathParams, 'name')) . '`';
        }
        if ($query) {
            $inputs[] = 'query `' . implode('`, `', array_column($query, 'name')) . '`';
        }
        if ($body) {
            $schema = reset($body['content'])['schema'];
            $inputs[] = 'dữ liệu gửi lên `' . implode('`, `', array_keys($schema['properties'] ?? [])) . '`';
        }
        $parts[] = "### Dữ liệu đầu vào\n" . ($inputs ? implode('; ', $inputs) : 'Không có dữ liệu đầu vào bắt buộc được khai báo.');
        $parts[] = "### Kết quả\nCác mã phản hồi được khai báo: `" . implode('`, `', array_keys($responses)) . '`.';

        return implode("\n\n", $parts);
    }

    private function operationId(string $method, string $path): string
    {
        $id = strtolower($method);
        foreach (explode('/', trim($path, '/')) as $seg) {
            if (preg_match('/^\{(\w+)\}$/', $seg, $m)) {
                $id .= 'By' . ucfirst(\Illuminate\Support\Str::camel($m[1]));
            } else {
                $id .= ucfirst(\Illuminate\Support\Str::camel(str_replace(['-', '.'], '_', $seg)));
            }
        }

        return $id;
    }

    // ── Tiêu đề endpoint ────────────────────────────────────────────────────────────────────────────

    private function summary(string $method, string $path, string $action): string
    {
        if (isset($this->labels['overrides'][$method . ' ' . $path])) {
            return $this->labels['overrides'][$method . ' ' . $path];
        }

        $all = explode('/', trim($path, '/'));
        $segs = array_values(array_filter($all, fn ($s) => ! in_array($s, self::CONTEXT, true)));
        $nouns = $this->labels['nouns'];
        $verbs = $this->labels['verbs'];
        $context = $this->labels['context'];
        $generic = $this->labels['generic'];
        $isParam = fn ($s) => str_starts_with($s, '{');

        $last = end($segs);
        $endsWithParam = $last !== false && $isParam($last);
        $literals = array_values(array_filter($segs, fn ($s) => ! $isParam($s)));
        // "portal/..." giữ ngữ cảnh cổng khách thuê cho bảng context
        $prevOf = fn (int $i) => $i > 0 ? $literals[$i - 1] : (in_array('portal', $all, true) ? 'portal' : '');

        $label = function (int $i) use (&$label, $literals, $nouns, $context, $generic, $prevOf, $verbs) {
            $s = $literals[$i];
            $prev = $prevOf($i);
            if (isset($context[$prev . '/' . $s])) {
                return $context[$prev . '/' . $s];
            }
            $base = $nouns[$s] ?? str_replace('-', ' ', $s);
            // Danh từ chung → ghép đối tượng cha ("cài đặt" + "thẻ cào")
            if (in_array($s, $generic, true) && $i > 0 && ! isset($verbs[$literals[$i - 1]])) {
                return $base . ' ' . $label($i - 1);
            }

            return $base;
        };

        $noun = function (int $from) use ($literals, $verbs, $nouns, $label) {
            for ($i = $from; $i >= 0; $i--) {
                $s = $literals[$i];
                if (isset($nouns[$s]) || ! isset($verbs[$s])) {
                    return $label($i);
                }
            }

            return '';
        };

        $n = count($literals);
        $lastLit = $n ? $literals[$n - 1] : '';
        $a = strtolower($action);

        // Báo cáo / thống kê: "Báo cáo doanh thu", "Thống kê công suất phòng"
        if ($method === 'GET' && ! $endsWithParam && in_array($literals[0] ?? '', ['reports', 'dashboard'], true) && $n >= 2) {
            return ($literals[0] === 'reports' ? 'Báo cáo ' : 'Thống kê ') . $label($n - 1) . (in_array('minihouse', $all, true) ? ' (MiniHouse)' : '');
        }

        $byAction = ['unblock' => 'Mở lịch %s', 'release' => 'Huỷ giữ chỗ %s', 'destroybatch' => 'Xoá nhiều %s', 'destroymany' => 'Xoá nhiều %s', 'hide' => 'Ẩn %s',
            'markallread' => 'Đánh dấu đã đọc tất cả %s', 'markread' => 'Đánh dấu đã đọc %s', 'unmark' => 'Bỏ đánh dấu %s',
            'deleteimage' => 'Xoá ảnh %s', 'deletereply' => 'Xoá phản hồi %s'];

        if (isset($byAction[$a])) {
            $text = sprintf($byAction[$a], $noun($n - 1));
        } elseif (! $endsWithParam && isset($verbs[$lastLit]) && ! (isset($nouns[$lastLit]) && $method === 'GET')) {
            $text = sprintf($verbs[$lastLit], $noun($n - 2));
        } elseif ($endsWithParam) {
            $obj = $noun($n - 1);
            $text = match ($method) {
                'GET' => 'Chi tiết ' . $obj,
                'DELETE' => 'Xoá ' . $obj,
                default => (str_contains($a, 'update') || in_array($method, ['PUT', 'PATCH'], true)) ? 'Cập nhật ' . $obj : $this->verbFromAction($a, $obj),
            };
        } else {
            $obj = $noun($n - 1);
            $text = match ($method) {
                'GET' => ($a === 'index' || str_starts_with($a, 'list') || $a === 'mine') ? 'Danh sách ' . $obj : 'Xem ' . $obj,
                'DELETE' => str_contains($a, 'batch') || str_contains($a, 'many') ? 'Xoá nhiều ' . $obj : 'Xoá ' . $obj,
                'PUT', 'PATCH' => 'Cập nhật ' . $obj,
                default => $this->verbFromAction($a, $obj),
            };
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
        if (in_array('minihouse', $all, true) && $all[0] === 'admin') {
            $text .= ' (MiniHouse)';
        } elseif (($all[0] ?? '') === 'guest') {
            $text .= ' (khách vãng lai)';
        }

        return $text;
    }

    private function verbFromAction(string $a, string $obj): string
    {
        $map = ['store' => 'Tạo %s', 'create' => 'Tạo %s', 'update' => 'Cập nhật %s', 'upsert' => 'Lưu %s', 'register' => 'Đăng ký %s', 'send' => 'Gửi %s',
            'toggle' => 'Bật / tắt %s', 'check' => 'Kiểm tra %s', 'select' => 'Chọn %s', 'apply' => 'Áp dụng %s', 'handle' => 'Nhận %s', 'scan' => 'Quét %s',
            'purchase' => 'Mua %s', 'issue' => 'Cấp %s', 'unlock' => 'Mở khoá %s', 'hold' => 'Giữ chỗ %s', 'block' => 'Khoá lịch %s', 'preview' => 'Xem trước %s',
            'confirm' => 'Xác nhận %s', 'lockdata' => 'Lấy dữ liệu khoá cửa', 'addextra' => 'Thêm khoản phát sinh vào đơn'];
        foreach ($map as $k => $v) {
            if ($a === $k || str_starts_with($a, $k)) {
                return sprintf($v, $obj);
            }
        }

        return 'Gửi yêu cầu ' . $obj;
    }

    // ── Schema ──────────────────────────────────────────────────────────────────────────────────────

    private function fieldLabel(string $field, bool $path = false): string
    {
        $f = $this->labels['fields'];
        if (isset($f[$field])) {
            return $f[$field];
        }
        if ($path) {
            return isset($this->labels['nouns'][$field]) ? 'Mã ' . $this->labels['nouns'][$field] : 'Mã định danh / tham số ' . $field;
        }
        if (preg_match('/^(.+)_id$/', $field, $m)) {
            return 'Mã ' . ($this->labels['nouns'][$m[1] . 's'] ?? $this->labels['nouns'][str_replace('_', '-', $m[1]) . 's'] ?? str_replace('_', ' ', $m[1]));
        }
        if (preg_match('/^is_(.+)$/', $field, $m)) {
            return 'Có ' . str_replace('_', ' ', $m[1]) . ' hay không';
        }

        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    private function ruleSchema(string $field, array $rules): array
    {
        $schema = ['type' => 'string'];
        $desc = $this->fieldLabel((string) preg_replace('/^.*\./', '', $field));
        $notes = [];
        $isFile = false;
        foreach ($rules as $r) {
            [$name, $arg] = array_pad(explode(':', (string) $r, 2), 2, null);
            switch ($name) {
                case 'integer': case 'int': case 'digits': case 'digits_between':
                    $schema['type'] = 'integer';
                    break;
                case 'numeric': case 'decimal':
                    $schema['type'] = 'number';
                    break;
                case 'boolean': case 'bool': case 'accepted': case 'declined':
                    $schema['type'] = 'boolean';
                    break;
                case 'array': case 'list':
                    $schema['type'] = 'array';
                    break;
                case 'file': case 'image': case 'mimes': case 'mimetypes':
                    $isFile = true;
                    $schema = ['type' => 'string', 'format' => 'binary'];
                    if ($arg && $name === 'mimes') {
                        $notes[] = 'định dạng ' . $arg;
                    }
                    break;
                case 'date': case 'date_format': case 'after': case 'before': case 'after_or_equal': case 'before_or_equal':
                    if ($schema['type'] === 'string' && ! isset($schema['format'])) {
                        $schema['format'] = $name === 'date_format' && $arg && str_contains($arg, 'H') && ! str_contains($arg, 'Y') ? 'time' : 'date';
                    }
                    if ($name === 'date_format' && $arg) {
                        $notes[] = 'định dạng `' . $arg . '`';
                    }
                    if (in_array($name, ['after', 'before', 'after_or_equal', 'before_or_equal'], true) && $arg) {
                        $notes[] = ['after' => '>', 'before' => '<', 'after_or_equal' => '≥', 'before_or_equal' => '≤'][$name] . ' `' . $arg . '`';
                    }
                    break;
                case 'email':
                    $schema['format'] = 'email';
                    break;
                case 'url':
                    $schema['format'] = 'uri';
                    break;
                case 'uuid': case 'ulid':
                    $schema['format'] = $name;
                    break;
                case 'nullable':
                    $schema['nullable'] = true;
                    break;
                case 'in':
                    if ($arg) {
                        $schema['enum'] = array_map(fn ($v) => trim($v, '"\''), explode(',', $arg));
                    }
                    break;
                case 'exists':
                    if ($arg) {
                        $notes[] = 'phải tồn tại trong `' . explode(',', $arg)[0] . '`';
                    }
                    break;
                case 'unique':
                    $notes[] = 'không được trùng';
                    break;
                case 'confirmed':
                    $notes[] = 'gửi kèm `' . $field . '_confirmation`';
                    break;
                case 'required_if': case 'required_with': case 'required_without': case 'required_unless': case 'prohibited_if': case 'required_with_all':
                    if ($arg) {
                        $notes[] = str_replace('_', ' ', $name) . ' `' . $arg . '`';
                    }
                    break;
                case 'regex':
                    $notes[] = 'theo mẫu `' . $arg . '`';
                    break;
            }
        }
        foreach ($rules as $r) {
            [$name, $arg] = array_pad(explode(':', (string) $r, 2), 2, null);
            if (! in_array($name, ['min', 'max', 'size', 'between'], true) || $arg === null) {
                continue;
            }
            $vals = explode(',', $arg);
            $t = $schema['type'];
            if ($isFile) {
                $notes[] = ($name === 'max' ? 'tối đa ' : 'tối thiểu ') . $vals[0] . ' KB';
                continue;
            }
            $num = is_numeric($vals[0]) ? $vals[0] + 0 : null;
            if ($num === null) {
                continue;
            }
            if (in_array($t, ['integer', 'number'], true)) {
                if ($name === 'min' || $name === 'between') {
                    $schema['minimum'] = $num;
                }
                if ($name === 'max') {
                    $schema['maximum'] = $num;
                }
                if ($name === 'between' && isset($vals[1]) && is_numeric($vals[1])) {
                    $schema['maximum'] = $vals[1] + 0;
                }
            } elseif ($t === 'array') {
                $schema[$name === 'max' ? 'maxItems' : 'minItems'] = (int) $num;
            } else {
                if ($name === 'min' || $name === 'between') {
                    $schema['minLength'] = (int) $num;
                }
                if ($name === 'max') {
                    $schema['maxLength'] = (int) $num;
                }
                if ($name === 'size') {
                    $schema['minLength'] = $schema['maxLength'] = (int) $num;
                }
            }
        }
        if ($notes) {
            $desc .= ' (' . implode('; ', $notes) . ')';
        }

        return ['schema' => $schema, 'description' => $desc, 'file' => $isFile];
    }

    private function requestBody(array $rules): array
    {
        $root = ['type' => 'object', 'properties' => []];
        $hasFile = false;

        foreach ($rules as $field => $r) {
            $field = (string) $field;
            $parts = explode('.', $field);
            $s = $this->ruleSchema($field, $r);
            $hasFile = $hasFile || $s['file'];
            $leaf = $s['schema'] + ['description' => $s['description']];

            $node = &$root;
            foreach ($parts as $i => $part) {
                $isLast = $i === count($parts) - 1;
                if ($part === '*') {
                    $node['type'] = 'array';
                    if ($isLast) {
                        $node['items'] = array_merge(is_array($node['items'] ?? null) ? $node['items'] : [], array_diff_key($leaf, ['description' => 1]));
                        break;
                    }
                    if (! is_array($node['items'] ?? null) || ($node['items']['type'] ?? 'object') !== 'object') {
                        $node['items'] = ['type' => 'object', 'properties' => []];
                    }
                    $node = &$node['items'];
                    continue;
                }
                if ($isLast) {
                    $existing = $node['properties'][$part] ?? [];
                    $node['properties'][$part] = array_merge($leaf, array_intersect_key($existing, ['items' => 1, 'properties' => 1]));
                    if (isset($existing['items']) || isset($existing['properties'])) {
                        $node['properties'][$part]['type'] = $existing['type'] ?? $leaf['type'];
                    }
                    if (in_array('required', $r, true)) {
                        $node['required'][] = $part;
                    }
                    break;
                }
                if (! isset($node['properties'][$part])) {
                    $node['properties'][$part] = ['type' => 'object', 'properties' => []];
                } elseif (($node['properties'][$part]['type'] ?? '') !== 'array' && ! isset($node['properties'][$part]['properties'])) {
                    $node['properties'][$part]['properties'] = [];
                    $node['properties'][$part]['type'] = 'object';
                }
                $node = &$node['properties'][$part];
            }
            unset($node);
        }

        $root = $this->tidy($root);
        $mime = $hasFile ? 'multipart/form-data' : 'application/json';
        $content = ['schema' => $root];
        if (! $hasFile) {
            $content['example'] = $this->exampleForSchema($root);
        }

        return ['required' => ! empty($root['required']), 'content' => [$mime => $content]];
    }

    private function tidy(array $s): array
    {
        if (isset($s['required'])) {
            $s['required'] = array_values(array_unique($s['required']));
        }
        if (isset($s['properties'])) {
            if (! $s['properties']) {
                unset($s['properties']);
            } else {
                foreach ($s['properties'] as $k => $p) {
                    $s['properties'][$k] = $this->tidy($p);
                }
            }
        }
        if (isset($s['items']) && is_array($s['items'])) {
            $s['items'] = $this->tidy($s['items']);
        }
        if (($s['type'] ?? '') === 'array' && ! isset($s['items'])) {
            $s['items'] = new stdClass();
        }

        return $s;
    }

    private function exampleForSchema(array $s, string $key = '')
    {
        if (isset($s['enum'])) {
            return $s['enum'][0];
        }
        $type = $s['type'] ?? 'string';
        if ($type === 'object') {
            $o = [];
            foreach ($s['properties'] ?? [] as $k => $p) {
                $o[$k] = $this->exampleForSchema($p, (string) $k);
            }

            return $o ?: new stdClass();
        }
        if ($type === 'array') {
            $items = is_array($s['items'] ?? null) ? $s['items'] : [];

            return $items ? [$this->exampleForSchema($items, $key)] : [];
        }
        if ($type === 'integer') {
            return max((int) ($s['minimum'] ?? 1), 1);
        }
        if ($type === 'number') {
            return max((float) ($s['minimum'] ?? 100000), 0) ?: 100000;
        }
        if ($type === 'boolean') {
            return true;
        }
        $fmt = $s['format'] ?? '';
        $k = strtolower($key);

        return match (true) {
            $fmt === 'date' => '2026-11-10',
            $fmt === 'time' => '14:00',
            $fmt === 'email' => 'khach@example.com',
            $fmt === 'uri' => 'https://365home.vn',
            $fmt === 'uuid' => '9d1c2b7e-4a51-4f3a-9a77-0c8f1e2d3b4a',
            str_contains($k, 'password') => 'Password@123',
            str_contains($k, 'phone') => '0901234567',
            str_contains($k, 'otp') => '123456',
            $k === 'cccd' || str_contains($k, 'cccd_number') || str_contains($k, 'id_number') => '079090001234',
            str_ends_with($k, '_id') || $k === 'id' => '1',
            str_contains($k, 'name') => 'Nguyễn Văn A',
            str_contains($k, 'address') => '12 Trần Phú, Đà Lạt',
            str_contains($k, 'code') => 'ABC123',
            default => isset($s['maxLength']) && $s['maxLength'] < 8 ? substr('abcdefgh', 0, (int) $s['maxLength']) : 'string',
        };
    }

    private function schemaOf($v): array
    {
        if ($v instanceof stdClass) {
            return ['type' => 'object'];
        }
        if (is_bool($v)) {
            return ['type' => 'boolean'];
        }
        if (is_int($v)) {
            return ['type' => 'integer'];
        }
        if (is_float($v)) {
            return ['type' => 'number'];
        }
        if (is_string($v)) {
            return ['type' => 'string'];
        }
        if ($v === null) {
            return ['nullable' => true];
        }
        if (is_array($v)) {
            if (array_is_list($v)) {
                return ['type' => 'array', 'items' => $v ? $this->schemaOf($v[0]) : new stdClass()];
            }
            $props = [];
            foreach ($v as $k => $x) {
                $props[$k] = $this->schemaOf($x);
            }

            return ['type' => 'object', 'properties' => $props];
        }

        return [];
    }

    // ── Tài liệu ────────────────────────────────────────────────────────────────────────────────────

    private function document(array $paths, array $tagCount): array
    {
        $tags = [];
        $groups = [];
        foreach (array_keys($this->cfg['accounts']) as $account) {
            foreach ($this->cfg['modules'] as $id => [$name, $group]) {
                $tag = $this->tagName($account, $id);
                if (! isset($tagCount[$tag])) {
                    continue;
                }
                $tags[] = ['name' => $tag, 'description' => "{$account} — {$name} ({$group}). {$tagCount[$tag]} endpoint."];
                $groups[$account][] = $tag;
            }
        }

        $accountLines = [];
        foreach ($this->cfg['accounts'] as $acc => $label) {
            if (isset($this->stats[$acc])) {
                $accountLines[] = "- **{$acc}** ({$this->stats[$acc]} endpoint) — {$label}.";
            }
        }

        $description = implode("\n", [
            'Toàn bộ API của hệ thống đặt phòng 365 HOME (Homestay + MiniHouse) trong **một file**, chia theo **loại tài khoản** rồi đến **module**:',
            '',
            implode("\n", $accountLines),
            '',
            'Tên mỗi nhóm (tag) có dạng `LOẠI TÀI KHOẢN · số module Tên module`. Badge trên tiêu đề endpoint: `[CÔNG_KHAI]` = không cần đăng nhập; `[CLIENT]` = token khách hàng; `[KHÁCH_THUÊ]` = token khách thuê MiniHouse; `[TÍCH_HỢP]` = hệ thống bên ngoài gọi vào; ADMIN: `[SUPER_ADMIN]` = luôn dùng được, `[MỌI_TÀI_KHOẢN_ADMIN]` = chỉ cần đăng nhập quản trị, `[TÀI_KHOẢN_CÓ_QUYỀN]` = tài khoản đối tác/nhân viên phải có quyền Shield ghi trong `x-permission`. Endpoint chỉ có `[SUPER_ADMIN]` thì đối tác gọi sẽ bị `403`.',
            '',
            '**Lấy token**',
            '- Khách hàng: `POST /auth/send-otp` → `POST /auth/verify-otp` (hoặc `POST /auth/login`, `POST /auth/register`) → dùng `token` làm Bearer.',
            '- Khách thuê MiniHouse: `POST /minihouse/portal/otp/request` → `POST /minihouse/portal/otp/verify` (hoặc `POST /minihouse/portal/login/password`).',
            '- Quản trị (Super Admin, chủ đối tác, nhân viên): `POST /admin/login` (email + mật khẩu). Super Admin gửi thêm header `X-Partner-Id` để làm việc trong một đối tác.',
            '',
            "Tổng cộng {$this->count} thao tác (PUT và PATCH cùng đường dẫn được liệt kê riêng). `x-controller` ghi controller xử lý để tra mã nguồn. Sinh tự động từ route thực tế, chú thích controller và luật validate trong mã nguồn (`php scripts/generate_openapi.php`); ví dụ response dựng từ `response()->json(...)` trong code — giá trị tính lúc chạy được thay bằng giá trị minh hoạ.",
        ]);

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => '365 HOME API - Toàn bộ hệ thống (CLIENT · KHÁCH THUÊ · ADMIN · TÍCH HỢP)',
                'version' => '1.0.0',
                'description' => $description,
            ],
            'servers' => [
                ['url' => 'https://365home.vn/api', 'description' => 'Production (tiền tố /api)'],
                ['url' => 'https://365home-cms.test/api', 'description' => 'Local (Laravel Herd)'],
            ],
            'tags' => $tags,
            'x-tagGroups' => array_map(fn ($acc) => ['name' => $acc, 'tags' => $groups[$acc]], array_keys($groups)),
            'components' => [
                'securitySchemes' => [
                    'clientAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'CLIENT — token Sanctum của khách hàng (verify-otp / login / register).'],
                    'tenantAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'KHÁCH THUÊ — token Sanctum của khách thuê MiniHouse (cổng /minihouse/portal).'],
                    'partnerAdminAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'ADMIN ĐỐI TÁC — token từ POST /admin/login của chủ đối tác / nhân viên đối tác.'],
                    'superAdminAuth' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'SUPER ADMIN — token từ POST /admin/login của tài khoản super_admin.'],
                ],
                'parameters' => [
                    'PartnerIdHeader' => ['name' => 'X-Partner-Id', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'],
                        'description' => 'Chỉ có tác dụng với Super Admin: chỉ làm việc trong đối tác này. Tài khoản đối tác bỏ qua header này.'],
                ],
                'responses' => [
                    'Unauthenticated' => ['description' => 'Thiếu hoặc sai token đăng nhập.', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message'], 'example' => ['message' => 'Unauthenticated.']]]],
                    'Forbidden' => ['description' => 'Tài khoản không có quyền thực hiện (hoặc gói dịch vụ không bao gồm tính năng này).', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Message'], 'example' => ['message' => 'Unauthorized.']]]],
                ],
                'schemas' => [
                    'Message' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string']], 'example' => ['message' => 'Thao tác thành công.']],
                    'ValidationError' => ['type' => 'object', 'description' => 'Lỗi validate của Laravel (HTTP 422).', 'properties' => [
                        'message' => ['type' => 'string'],
                        'errors' => ['type' => 'object', 'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']]],
                    ], 'example' => ['message' => 'Trường phone là bắt buộc.', 'errors' => ['phone' => ['Trường phone là bắt buộc.']]]],
                ],
            ],
            'paths' => $paths,
        ];
    }
}
