<?php

namespace Core\Template;

use Core\Template\Render\Data;
use function Core\Utils\path_join;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Error;

/**
 * This trait expose methods for compile and prepare instance metadata for the rendering.
 */
trait Template
{
    /**
     * @var array $template_file_conf This property provide data config for template name making.
     */
    private static $template_file_conf = [
        "ext" => "template.html",
    ];

    /**
     * @var string|null $__template_name This property its runtime setting whit the template name path
     */
    private static $__template_name = null;

    public $yield = "main";

    /**
     * Generate array of type [name => value] from the propertyes
     * @param ReflectionClass &$reflection
     * @return array
     */
    private function __reflectPropertyes(ReflectionClass &$reflection, array $blacklist)
    {
        $data = [];
        $propertyes = $reflection->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED);

        foreach ($propertyes as $key => $prop) {
            $name = $prop->getName();
            if (false === array_search($name, $blacklist)) {
                $data[$name] = $prop->getValue($this);
            }
        }

        return $data;
    }

    /**
     * Generate array of type [(string) name => Closure] from the methods
     * @param ReflectionClass &$reflection
     * @return array
     */
    private function __reflectMethods(ReflectionClass &$reflection, array $blacklist)
    {
        $data = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);

        foreach ($methods as $key => $method) {
            $name = $method->getName();
            if (false === array_search($name, $blacklist)) {
                $data[$name] = $method->getClosure($this);
            }
        }

        return $data;
    }

    /**
     * Make an array of type [(string) name => array] from the metadata of the methods, the parameter information will be included in the form:
     * ```text
     *  [
     *      method => [
     *          [ param => [
     *              "type" => mixed, 
     *              "index" => int, 
     *              "required" => boolean,
     *              "default" ?=> mixed|null
     *              ]
     *          ]
     *      ] 
     *  ]
     * ```
     * @param ReflectionClass &$reflection
     * @return array
     */
    private static function __reflectDataFromSure(ReflectionClass &$reflection, $blacklist)
    {
        $data = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED);

        foreach ($methods as $key => $method) {

            $name = $method->getName();
            if (false === array_search($name, $blacklist)) {

                $parameters = $method->getParameters();
                $mdata = [
                    "name" => $name,
                    "params" => []
                ];

                foreach ($parameters  as $key => $parameter) {
                    $mdata["params"][$parameter->getName()] = [
                        "type" => $parameter->getType(),
                        "index" => $parameter->getPosition(),
                        "required" => !$parameter->isOptional()
                    ];

                    if ($parameter->isOptional()) {
                        try {
                            $mdata["params"][$parameter->getName()]["default"] = $parameter->getDefaultValue();
                        } catch (\ReflectionException $e) {
                        }
                    }
                }

                $data[] = $mdata;
            }
        }

        return $data;
    }

    /**
     * Make the metadata methods for building template 
     * 
     * @param bool $isBuildMode [optional] If is true compile the methods metadata, not else
     * @param string[] $exclude [optional] Exclude a list of method names
     * 
     * @return Data
     */
    public static function __toBuild(bool $isBuildMode = true, array $exclude = null): Data
    {
        $reflection = new ReflectionClass(self::class);

        $name = $reflection->getShortName();
        $filename = $reflection->getFileName();
        $namespace = $reflection->getNamespaceName();

        self::$__template_name = join(".", [$name, self::$template_file_conf['ext']]);
        self::$__template_name = path_join(DIRECTORY_SEPARATOR, dirname($filename), self::$__template_name);

        if (!file_exists(self::$__template_name)) {
            throw new Error("Not Found Template File $name from [$namespace] controler.", 500);
        }

        if (!is_readable(self::$__template_name)) {
            throw new Error("Template File [$name] doesn't us readable", 500);
        }

        $data = new Data;
        $data->name = self::$__template_name;
        $data->namespace = $namespace;
        $data->properties = [
            "Template" => [
                "name" => $name,
                "namespace" => $namespace,
                "render" => require "./Lib/templateRenderHelper.php"
            ]
        ];

        if ($isBuildMode) {

            $reservedNames =  require __DIR__ . "/Lib/reserved_names.php";
            $exclude = !!$exclude ? array_merge($exclude, $reservedNames) : $reservedNames;
            $data->methods = self::__reflectDataFromSure($reflection, $exclude);
        }

        return $data;
    }

    /**
     * Make the instance metadata as array for the renderer
     * 
     * @return Data
     */
    public function __toRender(): Data
    {
        $reflection = new ReflectionClass($this);
        $buildData = self::__toBuild(false);

        /** @var string[] $reservedNames */
        $reservedNames =  require __DIR__ . "/Lib/reserved_names.php";

        $data = new Data;
        $data->name = $buildData->name;
        $data->namespace = $buildData->namespace;

        $data->properties = array_merge_recursive(
            $this->__reflectPropertyes($reflection, $reservedNames),
            $buildData->properties
        );

        $data->methods =  $this->__reflectMethods($reflection, $reservedNames);

        return $data;
    }
}
