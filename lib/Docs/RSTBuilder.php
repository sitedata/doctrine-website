<?php

declare(strict_types=1);

namespace Doctrine\Website\Docs;

use Doctrine\RST\Builder;
use Doctrine\RST\Document;
use Doctrine\Website\Projects\Project;
use Doctrine\Website\Projects\ProjectVersion;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_string;
use function iterator_to_array;
use function mkdir;
use function preg_match;
use function preg_replace;
use function realpath;
use function sprintf;
use function str_replace;
use function strpos;
use function trim;

class RSTBuilder
{
    public const RST_TEMPLATE = <<<TEMPLATE
.. raw:: html
    {% block sidebar %}

{{ sidebar }}

.. raw:: html
    {% endblock %}


.. raw:: html
    {% block content %}

{% verbatim %}

{{ content }}

{% endverbatim %}

.. raw:: html
    {% endblock %}

TEMPLATE;

    public const PARAMETERS_TEMPLATE = <<<TEMPLATE
---
layout: "documentation"
indexed: true
title: "%s"
menuSlug: "projects"
docsSlug: "%s"
docsPage: true
docsIndex: %s
docsVersion: "%s"
sourceFile: "%s"
permalink: "none"
controller: ['Doctrine\Website\Controllers\DocumentationController', 'view']
---
%s
TEMPLATE;

    public const DEFAULT_SIDEBAR = <<<SIDEBAR
.. toctree::
    :depth: 3
    :glob:

    *
SIDEBAR;

    /** @var string */
    private $sourcePath;

    /** @var Builder */
    private $builder;

    /** @var Filesystem */
    private $filesystem;

    /** @var string */
    private $projectsPath;

    /** @var string */
    private $tmpPath;

    public function __construct(
        string $sourcePath,
        Builder $builder,
        Filesystem $filesystem,
        string $projectsPath
    ) {
        $this->sourcePath   = $sourcePath;
        $this->builder      = $builder;
        $this->filesystem   = $filesystem;
        $this->projectsPath = $projectsPath;
        $this->tmpPath      = $this->sourcePath . '/../docs';
    }

    /**
     * @return Document[]
     */
    public function getDocuments() : array
    {
        return $this->builder->getDocuments();
    }

    public function projectHasDocs(Project $project) : bool
    {
        return file_exists($this->getProjectDocsPath($project) . '/en/index.rst');
    }

    public function buildRSTDocs(Project $project, ProjectVersion $version) : void
    {
        $this->copyRst($project, $version);

        $this->buildRst($project, $version);

        $this->postRstBuild($project, $version);
    }

    private function copyRst(Project $project, ProjectVersion $version) : void
    {
        $outputPath = $this->getProjectVersionTmpPath($project, $version);

        // clear tmp directory first
        $this->filesystem->remove($this->findFiles($outputPath));

        $docsPath = $this->getProjectDocsPath($project) . '/en';

        // check if we have an explicit sidebar file to use
        // otherwise just use the default autogenerated sidebar
        $sidebarPath = $docsPath . '/sidebar.rst';
        $sidebar     = file_exists($sidebarPath) ? $this->getFileContents($sidebarPath) : self::DEFAULT_SIDEBAR;

        $files = $this->getSourceFiles($docsPath);

        foreach ($files as $file) {
            $path = str_replace($this->getProjectDocsPath($project) . '/en/', '', $file);

            $newPath = $outputPath . '/' . $path;

            $this->ensureDirectoryExists(dirname($newPath));

            $content = trim($this->getFileContents($file));

            // fix incorrect casing of note
            $content = str_replace('.. Note::', '.. note::', $content);

            // fix :maxdepth: to :depth:
            $content = str_replace(':maxdepth:', ':depth:', $content);

            // get rid of .. include:: toc.rst
            $content = str_replace('.. include:: toc.rst', '', $content);

            // stuff from doctrine1 docs
            if ($project->getSlug() === 'doctrine1') {
                $content = preg_replace("/:code:(.*)\n/", '$1', $content);
                $content = preg_replace('/:php:(.*):`(.*)`/', '$2', $content);
                $content = preg_replace('/:file:`(.*)`/', '$1', $content);
                $content = preg_replace('/:code:`(.*)`/', '$1', $content);
                $content = preg_replace('/:literal:`(.*)`/', '$1', $content);
                $content = preg_replace('/:token:`(.*)`/', '$1', $content);
                $content = str_replace('.. productionlist::', '', $content);
                $content = preg_replace('/.. rubric:: Notes/', '', $content);
                $content = preg_replace("/.. sidebar:: (.*)\n/", '$1', $content);
            }

            // put the content in the RST template
            $content = str_replace('{{ content }}', $content, self::RST_TEMPLATE);

            // replace the sidebar
            $content = str_replace('{{ sidebar }}', $sidebar, $content);

            // append the source file name to the content so we can parse it back out
            // for use in the build process
            $content .= sprintf('{{ SOURCE_FILE:/en/%s }}', $path);

            file_put_contents($newPath, $content);
        }
    }

    private function buildRst(Project $project, ProjectVersion $version) : void
    {
        $outputPath = $this->getProjectVersionSourcePath($project, $version);

        // clear projects docs source in the source folder before rebuilding
        $this->filesystem->remove($this->findFiles($outputPath));

        // we have to get a fresh builder due to how the RST parser works
        $this->builder = $this->builder->recreate();

        $this->builder->build(
            $this->getProjectVersionTmpPath($project, $version),
            $outputPath,
            false
        );
    }

    private function postRstBuild(Project $project, ProjectVersion $version) : void
    {
        $projectDocsVersionPath = $this->getProjectVersionSourcePath($project, $version);

        $this->removeMetaFiles($projectDocsVersionPath);

        $files = $this->getBuildFiles($projectDocsVersionPath);

        foreach ($files as $file) {
            $content = trim($this->getFileContents($file));

            // extract title from <h1>
            preg_match('/<h1>(.*)<\/h1>/', $content, $matches);

            $title = '';
            if ($matches !== []) {
                $title = $matches[1];
            }

            // modify anchors and headers
            $content = preg_replace(
                '/<a id="(.*)"><\/a><h(\d)>(.*)<\/h(\d)>/',
                '<a class="section-anchor" id="$1" name="$1"></a><h$2 class="section-header"><a href="#$1">$3<i class="fas fa-link"></i></a></h$2>',
                $content
            );

            // grab the html out of the <body> because that is all we need
            preg_match('/<body>(.*)<\/body>/s', $content, $matches);

            $content = $matches[1] ?? $content;

            if (strpos($file, '.html') !== false) {
                // parse out the source file that generated this file
                preg_match('/<p>{{ SOURCE_FILE:(.*) }}<\/p>/', $content, $match);

                $sourceFile = $match[1];

                // get rid of this special syntax in the content
                $content = str_replace($match[0], '', $content);

                $newContent = sprintf(
                    self::PARAMETERS_TEMPLATE,
                    $title,
                    $project->getDocsSlug(),
                    strpos($file, 'index.html') !== false ? 'true' : 'false',
                    $version->getSlug(),
                    $sourceFile,
                    $content
                );
            } else {
                $newContent = $content;
            }

            file_put_contents($file, $newContent);
        }
    }

    private function removeMetaFiles(string $path) : void
    {
        $finder = new Finder();
        $finder->in($path)->name('meta.php')->files();

        $files = $this->finderToArray($finder);

        $this->filesystem->remove($files);
    }

    private function getProjectDocsPath(Project $project) : string
    {
        $realpath = realpath($project->getAbsoluteDocsPath($this->projectsPath));
        assert(is_string($realpath));

        return $realpath;
    }

    /**
     * @return string[]
     */
    private function getSourceFiles(string $path) : array
    {
        if (! is_dir($path)) {
            return [];
        }

        $finder = $this->getFilesFinder($path);

        $finder->name('*.rst');
        $finder->notName('toc.rst');

        return $this->finderToArray($finder);
    }

    /**
     * @return string[]
     */
    private function getBuildFiles(string $path) : array
    {
        if (! is_dir($path)) {
            return [];
        }

        $finder = $this->getFilesFinder($path);

        $files = $this->finderToArray($finder);

        return array_filter($files, function (string $file) {
            return strpos($file, 'meta.php') === false;
        });
    }

    private function getFilesFinder(string $path) : Finder
    {
        $finder = new Finder();
        $finder->in($path)->files();

        return $finder;
    }

    /**
     * @return string[]
     */
    private function findFiles(string $path) : array
    {
        if (! is_dir($path)) {
            return [];
        }

        return $this->finderToArray($this->getFilesFinder($path));
    }

    /**
     * @return string[]
     */
    private function finderToArray(Finder $finder) : array
    {
        return array_values(array_map(function (SplFileInfo $file) {
            return $file->getRealPath();
        }, iterator_to_array($finder)));
    }

    private function ensureDirectoryExists(string $dir) : void
    {
        if (is_dir($dir)) {
            return;
        }

        if (file_exists($dir)) {
            return;
        }

        // Without the @ this fails on travis ci with error: "mkdir(): File exists"
        @mkdir($dir, 0777, true);
    }

    private function getProjectVersionSourcePath(Project $project, ProjectVersion $version) : string
    {
        return $this->sourcePath . '/projects/' . $project->getDocsSlug() . '/en/' . $version->getSlug();
    }

    private function getProjectVersionTmpPath(Project $project, ProjectVersion $version) : string
    {
        return $this->tmpPath . '/' . $project->getDocsSlug() . '/en/' . $version->getSlug();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function getFileContents(string $path) : string
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Could not get contents of file %s', $path));
        }

        return $contents;
    }
}
