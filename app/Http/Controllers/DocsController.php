<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

class DocsController extends Controller
{
    private string $docsPath;

    public function __construct()
    {
        $this->docsPath = base_path('docs/users');
    }

    public function index(): Response
    {
        $sidebar = $this->buildSidebar();

        if (empty($sidebar) || empty($sidebar[0]['pages'])) {
            abort(404);
        }

        $firstPage = $sidebar[0]['pages'][0]['slug'];

        return $this->renderPage($firstPage, $sidebar);
    }

    public function show(string $path): Response
    {
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404);
        }

        $sidebar = $this->buildSidebar();

        return $this->renderPage($path, $sidebar);
    }

    private function renderPage(string $slug, array $sidebar): Response
    {
        $filePath = $this->resolveFilePath($slug);

        if (! $filePath || ! File::exists($filePath)) {
            abort(404);
        }

        $raw = File::get($filePath);
        $parsed = $this->parseFrontmatter($raw);

        $sidebar = array_map(function ($section) use ($slug) {
            $section['pages'] = array_map(function ($page) use ($slug) {
                $page['active'] = $page['slug'] === $slug;

                return $page;
            }, $section['pages']);

            return $section;
        }, $sidebar);

        return Inertia::render('docs/Show', [
            'sidebar' => $sidebar,
            'content' => $parsed['content'],
            'currentPage' => $slug,
            'pageTitle' => $parsed['title'],
        ]);
    }

    private function buildSidebar(): array
    {
        $sections = [];

        if (! File::isDirectory($this->docsPath)) {
            return [];
        }

        $directories = collect(File::directories($this->docsPath))->sort()->values();

        foreach ($directories as $dir) {
            $dirName = basename($dir);
            $files = collect(File::files($dir))
                ->filter(fn ($file) => $file->getExtension() === 'md')
                ->sortBy(fn ($file) => $file->getFilename())
                ->values();

            $pages = [];
            $sectionAdmin = false;
            $sectionName = null;

            foreach ($files as $file) {
                $parsed = $this->parseFrontmatter(File::get($file->getPathname()));
                $slug = $dirName.'/'.preg_replace('/^\d+-/', '', $file->getFilenameWithoutExtension());

                $isAdmin = $parsed['admin'] ?? false;
                if ($isAdmin) {
                    $sectionAdmin = true;
                }

                if (isset($parsed['section'])) {
                    $sectionName = $parsed['section'];
                }

                $pages[] = [
                    'title' => $parsed['title'] ?? $this->titleFromFilename($file->getFilenameWithoutExtension()),
                    'slug' => $slug,
                    'active' => false,
                ];
            }

            if (! empty($pages)) {
                $sections[] = [
                    'name' => $sectionName ?? $this->titleFromDirectory($dirName),
                    'admin' => $sectionAdmin,
                    'pages' => $pages,
                ];
            }
        }

        return $sections;
    }

    private function resolveFilePath(string $slug): ?string
    {
        $parts = explode('/', $slug);
        if (count($parts) !== 2) {
            return null;
        }

        [$dir, $page] = $parts;
        $dirPath = $this->docsPath.'/'.$dir;

        if (! File::isDirectory($dirPath)) {
            return null;
        }

        $files = File::files($dirPath);
        foreach ($files as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $nameWithoutPrefix = preg_replace('/^\d+-/', '', $file->getFilenameWithoutExtension());
            if ($nameWithoutPrefix === $page) {
                return $file->getPathname();
            }
        }

        return null;
    }

    private function parseFrontmatter(string $content): array
    {
        if (! str_starts_with($content, '---')) {
            return ['content' => $content, 'title' => null, 'admin' => false];
        }

        $parts = preg_split('/^---\s*$/m', $content, 3);

        if (count($parts) < 3) {
            return ['content' => $content, 'title' => null, 'admin' => false];
        }

        $meta = Yaml::parse($parts[1]) ?? [];

        return [
            'content' => trim($parts[2]),
            'title' => $meta['title'] ?? null,
            'section' => $meta['section'] ?? null,
            'admin' => $meta['admin'] ?? false,
        ];
    }

    private function titleFromFilename(string $filename): string
    {
        $name = preg_replace('/^\d+-/', '', $filename);

        return str_replace('-', ' ', ucfirst($name));
    }

    private function titleFromDirectory(string $dirname): string
    {
        return ucwords(str_replace('-', ' ', $dirname));
    }
}
