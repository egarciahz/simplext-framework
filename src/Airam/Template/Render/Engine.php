<?php

namespace Airam\Template\Render;

use Airam\Template\LayoutInterface;
use Airam\Template\TemplateInterface;
use LightnCandy\{LightnCandy, SafeString};

use function Airam\Template\Lib\{
    is_layout,
    makeTemplateFileName,
    matchFilesByExtension,
    closureCodeCompiler,
    cleanFileName
};
use function Airam\Commons\{
    path_join,
    loadResource
};

use ErrorException;
use Closure;

class Engine
{
    private static $context = [];
    private $config;

    private static $partials = [];
    private $root;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->root = getenv("ROOT_DIR");
    }

    /**
     * load helpers and partial bundlers
     * @param bool $isDevMode
     */
    private function loadResources(bool $isDevMode = true)
    {
        $helpers = path_join(DIRECTORY_SEPARATOR, $this->root, ".cache", $this->config["helpers"]["buildDir"], "helpers.bundle.php");
        $partials = path_join(DIRECTORY_SEPARATOR, $this->root, ".cache", $this->config["partials"]["buildDir"], "partials.bundle.php");

        $helpers = loadResource($helpers);
        self::$partials = loadResource($partials);

        /** prepare context */
        return $this->prepare($isDevMode, [
            "helpers" =>  $helpers
        ]);
    }

    /**
     * @param string $code php code as string
     * @param string $dir path folder for make file
     * @param string $filename
     * 
     * @return string absolute file path
     */
    private function bundle(string $code, string $dir, string $filename): string
    {
        $code = join(PHP_EOL, [
            "<?php",
            "namespace Airam\Cache;",
            "use Airam\Application;",
            "use function Airam\Commons\{path_join,randomId,class_use};",
            "use function Airam\Template\Lib\{is_layout,is_template};",
            $code,
            "?>"
        ]);

        if (file_exists($dir)) {
            $file = path_join(DIRECTORY_SEPARATOR, $dir, $filename);
            if (!file_put_contents($file, $code)) {
                throw new ErrorException("Could don't create [{$filename}] file when compiling.");
            }

            return $file;
        }

        throw new ErrorException("Can not generate $filename file under {$dir}!!\n");
    }

    /**
     * @param string[] $paths array of available file paths
     * @param string $buildDir path folder for make file
     */
    protected function compileHelpers(array $paths, string $buildDir)
    {
        $helpers = [];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                error_log("Can not find helper file [{$path}]!\n.");
                continue;
            }

            if (!is_readable($path)) {
                error_log("Can not read helper file [{$path}]!\n.");
                continue;
            }

            $helper = loadResource($path);

            if ($helper instanceof Closure) {
                $name = cleanFileName($path);
                $code = closureCodeCompiler($helper, $name);

                array_push($helpers, $code);
            }

            if (gettype($helper) === "array") {
                foreach ($helper as $name => $predicate) {
                    if ($predicate instanceof Closure) {
                        $code = closureCodeCompiler($predicate, $name);
                        array_push($helpers, $code);
                        continue;
                    }

                    array_push($helpers, "\"{$name}\" => " . var_export($predicate, true));
                }
            }
        }

        $code = "return [" . join("," . PHP_EOL, $helpers) . "]";
        return $this->bundle($code, $buildDir, "helpers.bundle.php");
    }

    /**
     * @param string[] $paths array of available file paths
     * @param string $buildDir path folder for make file
     */
    protected function compilePartials(array $paths, string $buildDir)
    {
        $partials = [];
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                error_log("Can not find partial file [{$path}]!\n.");
                continue;
            }

            if (!is_readable($path)) {
                error_log("Could not read partial file [{$path}]!\n.");
                continue;
            }

            $name = cleanFileName($path);
            $partial = new SafeString(file_get_contents($path));

            $code = LightnCandy::compilePartial($partial, [
                "prepartial" => function ($context, $template, $name) {
                    return "<!-- partial start: $name -->$template<!-- partial end: $name -->";
                }
            ]);
            array_push($partials, "\"{$name}\" => {$code}");
        }

        $code = "return [" . join("," . PHP_EOL, $partials) . "]";
        return $this->bundle($code, $buildDir, "partials.bundle.php");
    }

    /**
     * @param string $path template file path
     * @param string $buildDir path folder for make file
     */
    protected function compileTemplate(string $path, string $buildDir)
    {
        if (!file_exists($path)) {
            return null;
        }

        $template = file_get_contents($path);
        $code = LightnCandy::compile($template, self::$context);
        $code = join(PHP_EOL, [
            "<?php ",
            $code
        ]);

        if (file_exists($buildDir)) {
            $file = makeTemplateFileName($path);
            $file = path_join(DIRECTORY_SEPARATOR, $buildDir, $file);

            $size = file_put_contents($file, $code);
            if ($size === 0) {
                throw new ErrorException("Could not create file during compilation of template: [$path]");
            }
        }

        return $file;
    }

    /**
     * @param LayoutInterface $layout
     * @param TemplateInterface $templates
     * 
     * @return string html code
     */
    public function layout($layout, ...$templates): string
    {
        if (!is_layout($layout)) {
            return null;
        }

        foreach ($templates as $template) {
            $layout->setYield($template->yield, $this->render($template));
        }

        return  $this->render($layout);
    }

    /**
     * @param TemplateInterface $object
     * @param bool $runtime enable runtime rendering
     * 
     * @return string html code
     */
    public function render($object, bool $isDevMode = true)
    {

        $data = $object->__toRender($isDevMode);

        if ($isDevMode) {

            $context = $this->context;
            $template = file_get_contents($data->file);
            $compiled = LightnCandy::compile($template, $context);
            $renderer = LightnCandy::prepare($compiled);

            $data->properties["Template"]["file"] = $data->file;
        } else {

            $buildDir = path_join(DIRECTORY_SEPARATOR, $this->root, ".cache", $this->config["templates"]["buildDir"]);
            $file = makeTemplateFileName($data->file);
            $file = path_join(DIRECTORY_SEPARATOR, $buildDir, $file);

            if (!file_exists($file)) {
                $file = $this->compileTemplate($data->file, $buildDir);
            }

            $data->properties["Template"]["file"] = $file;
            $renderer = require $file;
        }

        $toRender = array_merge_recursive($data->properties, $data->methods);
        return $renderer($toRender);
    }

    public function prepare(bool $isDevMode = true, array $overrides = [])
    {

        $context = [
            "flags" => ($isDevMode ?
                (LightnCandy::FLAG_ERROR_EXCEPTION | LightnCandy::FLAG_RENDER_DEBUG)
                : LightnCandy::FLAG_ERROR_LOG) | // options for error catching and debug
                ($isDevMode ? LightnCandy::FLAG_STANDALONEPHP : LightnCandy::FLAG_BESTPERFORMANCE) |
                LightnCandy::FLAG_HANDLEBARSJS_FULL |
                LightnCandy::FLAG_ADVARNAME |
                LightnCandy::FLAG_NAMEDARG |
                LightnCandy::FLAG_PARENT,
            "prepartial" => function ($context, $template, $name) {
                return "<!-- partial start: $name -->$template<!-- partial end: $name -->";
            }
        ];

        $this->context = array_merge($context, $overrides);

        return $this->context;
    }

    public function build(bool $isDevMode = true)
    {


        $this->compileHelpers(
            matchFilesByExtension(
                path_join(DIRECTORY_SEPARATOR, $this->root, $this->config["helpers"]["dir"]),
                $this->config["helpers"]["fileExtension"],
                $this->config["helpers"]["excludeDir"],
            ),
            path_join(DIRECTORY_SEPARATOR, $this->root, ".cache", $this->config["helpers"]["buildDir"])
        );

        $this->compilePartials(
            matchFilesByExtension(
                path_join(DIRECTORY_SEPARATOR, $this->root, $this->config["partials"]["dir"]),
                $this->config["partials"]["fileExtension"],
                $this->config["partials"]["excludeDir"],
            ),
            path_join(DIRECTORY_SEPARATOR, $this->root, ".cache", $this->config["partials"]["buildDir"])
        );

        $this->loadResources($isDevMode);

        if (!$isDevMode) {
            throw new \Error("Production mode not yet implemented");
            exit;
            # for Production
            /*
            $templates = matchFilesByExtension(
                path_join(DIRECTORY_SEPARATOR, $this->root, $this->config["templates"]["dir"]),
                $this->config["templates"]["fileExtension"],
                $this->config["templates"]["excludeDir"]
            );

            foreach ($templates as $path) {
                $this->compileTemplate(
                    $path,
                    path_join(DIRECTORY_SEPARATOR, $this->root, ".cache", $this->config["templates"]["buildDir"])
                );
            }
        */
        }
    }
}
