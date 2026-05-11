<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class FilamentResourceCommand extends Command
{
    protected $signature = 'module:make:filament-resource {name} {module}';
    protected $description = 'Create a Filament resource in a specific module';

    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        parent::__construct();
        $this->files = $files;
    }

    public function handle()
    {
        $name = $this->argument('name');
        $module = $this->argument('module');

        $this->createResourceFiles($name, $module);

        $this->info("Filament resource created successfully in module {$module}.");
    }

    protected function createResourceFiles($name, $module)
    {
        $files = [
            'Resource' => "Resources/{$name}Resource.php",
            'Form' => "Resources/{$name}Resource/Forms/{$name}Form.php",
            'Table' => "Resources/{$name}Resource/Tables/{$name}Table.php",
            'ListPage' => "Resources/{$name}Resource/Pages/List{$name}.php",
            'CreatePage' => "Resources/{$name}Resource/Pages/Create{$name}.php",
            'EditPage' => "Resources/{$name}Resource/Pages/Edit{$name}.php",
            'Action' => "Resources/{$name}Resource/Tables/Actions/{$name}Action.php",
            'BulkAction' => "Resources/{$name}Resource/Tables/BulkActions/{$name}BulkAction.php",
            'Filter' => "Resources/{$name}Resource/Tables/Filters/{$name}Filter.php",
            'Plugin' => "{$name}Plugin.php",
        ];

        foreach ($files as $type => $path) {
            $this->createFile($name, $module, $type, $path);
        }
    }

    protected function createFile($name, $module, $type, $path)
    {
        $fullPath = $this->getFullPath($module, $path);
        $this->ensureDirectoryExists(dirname($fullPath));

        if ($this->files->exists($fullPath)) {
            $this->warn("File already exists: {$fullPath}");
            return;
        }

        $content = $this->generateFileContent($name, $module, $type);

        $this->files->put($fullPath, $content);
        $this->info("Created: {$fullPath}");
    }

    protected function getFullPath($module, $file)
    {
        return base_path("Modules/{$module}/App/Filament/{$file}");
    }

    protected function ensureDirectoryExists($directory)
    {
        if (!$this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }
    }

    protected function generateFileContent($name, $module, $type)
    {
        $stub = $this->getStub($type);
        $namespace = $this->getNamespace($module, $name, $type);
        $className = $this->getClassName($name, $type);
    
        return $this->replacePlaceholders($stub, [
            'namespace' => $namespace,
            'class' => $className,
            'module' => $module,
            'resource' => $name,
            'resourceLowercase' => Str::lower($name),
            'resourceNamespace' => "Modules\\{$module}\\App\\Filament\\Resources",
        ]);
    }

    protected function getStub($type)
    {
        $stubName = match ($type) {
            'ListPage' => 'list-page',
            'CreatePage' => 'create-page',
            'EditPage' => 'edit-page',
            default => Str::kebab($type),
        };
        $stubPath = base_path("stubs/filament-module/{$stubName}.stub");
    
        if (!file_exists($stubPath)) {
            throw new \Exception("Stub file not found: {$stubPath}");
        }
    
        return $this->files->get($stubPath);
    }

    protected function getNamespace($module, $name, $type)
    {
        $base = "Modules\\{$module}\\App\\Filament";

        switch ($type) {
            case 'Resource':
                return "{$base}\\Resources";
            case 'Plugin':
                return $base;
            case 'Form':
                return "{$base}\\Resources\\{$name}Resource\\Forms";
            case 'Table':
                return "{$base}\\Resources\\{$name}Resource\\Tables";
            case 'Action':
                return "{$base}\\Resources\\{$name}Resource\\Tables\\Actions";
            case 'BulkAction':
                return "{$base}\\Resources\\{$name}Resource\\Tables\\BulkActions";
            case 'Filter':
                return "{$base}\\Resources\\{$name}Resource\\Tables\\Filters";
            case 'ListPage':
            case 'CreatePage':
            case 'EditPage':
                return "{$base}\\Resources\\{$name}Resource\\Pages";
            default:
                return "{$base}\\Resources";
        }
    }

    protected function getClassName($name, $type)
    {
        switch ($type) {
            case 'Resource':
                return "{$name}Resource";
            case 'Form':
                return "{$name}Form";
            case 'Table':
                return "{$name}Table";
            case 'Action':
                return "{$name}Action";
            case 'BulkAction':
                return "{$name}BulkAction";
            case 'Filter':
                return "{$name}Filter";
            case 'ListPage':
                return "List{$name}";
            case 'CreatePage':
                return "Create{$name}";
            case 'EditPage':
                return "Edit{$name}";
            case 'Plugin':
                return "{$name}Plugin";
            default:
                return $name;
        }
    }

    protected function replacePlaceholders($stub, $replacements)
    {
        return str_replace(
            array_map(fn($key) => "{{ {$key} }}", array_keys($replacements)),
            array_values($replacements),
            $stub
        );
    }
}