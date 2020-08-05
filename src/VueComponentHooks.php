<?php

declare(strict_types=1);

namespace Azonmedia\VueComponentHooks;

use Azonmedia\Exceptions\InvalidArgumentException;
use Azonmedia\Exceptions\RunTimeException;
use Azonmedia\Translator\Translator as t;
use Azonmedia\Utilities\AlphaNumUtil;
use Azonmedia\Utilities\FilesUtil;
use Composer\Package\Package;

/**
 * Class VueComponentHooks
 * @package Azonmedia\VueComponentHooks
 *
 * Generates Vue components that represent hooks into other components.
 * To be used together with guzaba-platform/guzaba-platform and the Vue component from it: ./app/public_src/components/hooks/ComponentHook.vue
 *
 * @see self::add()
 */
class VueComponentHooks
{

    /**
     * Where the hooks should be dumped
     * @var string
     */
    private string $component_hooks_dir;

    /**
     * A json file with webpack aliases.
     * @var
     */
    private string $aliases_file;

    /**
     * The alises loaded from the webpack aliases json file
     * @var array
     */
    private array $aliases = [];

    /**
     * The defined hooks. A three dimensional array (first two ara associative, the last one is indexed)
     * @example $hooks[$component_name][$hook_name][0] = $hooked_component_name
     * @var array
     */
    private array $hooks = [];

    /**
     * VueComponentHooks constructor.
     * @param string $component_hooks_dir The directory where the hooks should be dumped
     * @param string $aliases_file Path to json file containing aliases used by webpack
     */
    public function __construct(string $component_hooks_dir, string $aliases_file = '')
    {
        if ($aliases_file) {
            $this->set_aliases_file($aliases_file);
        }
        $this->set_component_hooks_dir($component_hooks_dir);

    }

    /**
     * Returns the directory where the hooks should be dumped
     * @return string
     */
    public function get_component_hooks_dir(): string
    {
        return $this->component_hooks_dir;
    }

    /**
     * Returns the webpack aliases file (if such was provided)
     * @return string
     */
    public function get_aliases_file(): string
    {
        return $this->aliases_file;
    }

    /**
     * Returns an associative array of the webpack aliases (as parsed from the json $aliases_file file)
     * @return array
     */
    public function get_aliases(): array
    {
        return $this->aliases;
    }

    /**
     * Adds a new hook.
     * The $hooked_component_name will be hooked to $component_name on $hook_name.
     * @example $FrontendHooks->add('@GuzabaPlatform.Navigation/components/AddLink.vue','_after_tabs','@GuzabaPlatform.Cms/components/hooks/AddLinkPage.vue');
     * It also validates does the $component_name and $hooked_component_name files exist and does the $hook_name exist in $component_name.
     * @param string $component_name The path to the vue template in which the hook is defined
     * @param string $hook_name The name of the hook
     * @param string $hooked_component_name The vue component that needs to be inserted
     */
    public function add(string $component_name, string $hook_name, string $hooked_component_name): void
    {
        if ($this->isset($component_name, $hook_name, $hooked_component_name)) {
            return;
        }
        //check does the hook actually exists in the file
        if (!$this->hook_exists($component_name, $hook_name, $error)) {
            throw new InvalidArgumentException($error);
        }
        if (!$this->component_file_exists($hooked_component_name, $file_error)) {
            throw new InvalidArgumentException($file_error);
        }
        if (!array_key_exists($component_name, $this->hooks)) {
            $this->hooks[$component_name] = [];
        }
        if (!array_key_exists($hook_name, $this->hooks[$component_name])) {
            $this->hooks[$component_name][$hook_name] = [];
        }
        $this->hooks[$component_name][$hook_name][] = $hooked_component_name;
    }

    /**
     * Returns the hooked components to the $component_name file and $hook_name
     * @param string $component_name
     * @param string $hook_name
     * @return array
     */
    public function get(string $component_name, string $hook_name): array
    {
        return $this->hooks[$component_name][$hook_name] ?? [];
    }

    /**
     * Returns all hooked components (with the component and hook) in a structured array
     * @return array
     */
    public function get_all(): array
    {
        return $this->hooks;
    }

    /**
     * @param string $component_name
     * @param string $hook_name
     * @param string $hooked_component_name
     * @return bool
     */
    public function isset(string $component_name, string $hook_name, string $hooked_component_name): bool
    {
        if (!array_key_exists($component_name, $this->hooks)) {
            return false;
        }
        if (!array_key_exists($hook_name, $this->hooks[$component_name])) {
            return false;
        }
        return in_array($hooked_component_name, $this->hooks[$component_name][$hook_name]);
    }

    /**
     * @param string $component_name
     * @param string $hook_name
     * @param string $hooked_component_name
     * @throws InvalidArgumentException
     * @throws RunTimeException
     */
    public function remove(string $component_name, string $hook_name, string $hooked_component_name): void
    {
        if (!$this->isset($component_name, $hook_name, $hooked_component_name)) {
            throw new RunTimeException(sprintf(t::_('There is no hooked component %1$s added for hook %2$s in component %3$s.'), $hooked_component_name, $component_name, $hook_name));
        }
        $pos = array_search($hooked_component_name, $this->hooks);
        unset($this->hooks[$component_name][$hook_name][$pos]);
        $this->hooks[$component_name][$hook_name] = array_values($this->hooks[$component_name][$hook_name]);
    }

    /**
     * Returns the file name based on the component name to file name
     *
     * @param string $component_name For example @GuzabaPlatform.Navigation/components/AddLink.vue
     * @return string ./vendor/guzaba-platform/navigation/app/public_src/src/components/AddLink.vue
     */
    public function resolve_component_name(string $component_name): string
    {
        $aliases = $this->get_aliases();
        if (!count($aliases)) { //there is nowhere to lookup
            return $component_name;
        }
        if (strpos($component_name,'@') === false) {
            return $component_name;
        }
        $aliased_path = '';
        if (preg_match('/@(.*)\//U', $component_name, $matches)) {
            $alias = '@'.$matches[1];
            $aliased_path = $this->resolve_alias($alias);
        }
        if (!$aliased_path) {
            throw new RunTimeException(sprintf(t::_('The provided alias "%1$s" is not defined in %2$s.'), $alias, $this->get_aliases_file() ));
        }
        $component_name = str_replace($alias, $aliased_path, $component_name);
        return $component_name;
    }

    /**
     * Resolves an alias to path.
     * Returns null if not found
     * @param string $alias
     * @return string|null
     */
    public function resolve_alias(string $alias): ?string
    {
        return array_key_exists($alias, $this->get_aliases()) ? $this->get_aliases()[$alias] : null;
    }

    /**
     * Checks does the provided $component_file Vue file exists
     * This can be the component that contains the hook or the hook itself (both are Vue files)
     * It will also resolve path alias if there is such in the pcath (@see self::resolve_component_name())
     * A string with an error is provided in $_file_error if it doesnt.
     * @param string $hooked_component_name
     * @return bool
     */
    public function component_file_exists(string $component_file, ?string &$_file_error = null): bool
    {
        $component_file = $this->resolve_component_name($component_file);
        $_file_error = FilesUtil::file_error($component_file, $is_writeable = false, $is_dir = false, 'component_name');
        return $_file_error ? false : true ;
    }

    /**
     * Returns bool does the provdied $hook_name in the provided $component_file exists.
     * A string with an error is provied in $_error if it doesnt.
     * @param string $component_file
     * @param string $hook_name
     * @param string|null $_error
     * @return bool
     * @throws InvalidArgumentException
     * @throws RunTimeException
     */
    public function hook_exists(string $component_file, string $hook_name, ?string &$_error = null): bool
    {
        if (!$this->component_file_exists($component_file, $file_error)) {
            $_error = $file_error;
            return false;
        }
        $component_file = $this->resolve_component_name($component_file);
        $file_contents = file_get_contents($component_file);
        if (!preg_match("/hook_name.*({$hook_name})/", $file_contents, $matches)) {
            $_error = sprintf(t::_('In file %1$s there is no hook "%2$s".'), $component_file, $hook_name);
            return false;
        }
        return true;
    }

    /**
     * Dumps the hooks to the specified component_hooks_dir in the constructor (@see self::get_component_hooks_dir()
     * @see self::dump_hook()
     * @throws RunTimeException
     */
    public function dump_hooks(): void
    {

        FilesUtil::empty_dir($this->get_component_hooks_dir());

        foreach ($this->hooks as $component_name => $component_data) {
            foreach ($component_data as $hook_name => $hooked_components) {
                $this->dump_hook($component_name, $hook_name, $hooked_components);
            }
        }
    }

    /**
     * @param string $component_name The component in which the hook is
     * @param string $hook_name
     * @param array $hooked_components An indexed array with path of hooked components
     * @throws RunTimeException
     */
    protected function dump_hook(string $component_name, string $hook_name, array $hooked_components): void
    {
        /** @var string $compiled_hook_path The path of the new vue component */
        $compiled_hook_path = $this->resolve_component_name($component_name);
        $compiled_hook_path = substr($compiled_hook_path, strpos($compiled_hook_path, '/vendor/') + strlen('/vendor/') );
        $compiled_hook_path = $this->get_component_hooks_dir().'/'.$compiled_hook_path;
        $compiled_hook_path = str_replace('.vue', '/', $compiled_hook_path);
        $compiled_hook_path .= $hook_name.'.vue';
        
        mkdir(dirname($compiled_hook_path), 0777, true);

        $vue_indentation = 4;

        $content_component_name = basename($compiled_hook_path,'.vue');
        $hooked_components_str = $import_components_str = $vue_components_str = '';
        foreach ($hooked_components as $hooked_component) {
            $hooked_component_name = basename($this->resolve_component_name($hooked_component), '.vue').'C';
            $hooked_components_str .= '<'.$hooked_component_name.'></'.$hooked_component_name.'>'.PHP_EOL;
            $import_components_str .= "import {$hooked_component_name} from '{$hooked_component}'".PHP_EOL;//leave the unresolved (aliased) name in the import
            $vue_components_str .= $hooked_component_name.','.PHP_EOL;
        }
        $hooked_components_str = AlphaNumUtil::indent( trim($hooked_components_str), 2, $vue_indentation);
        $import_components_str = AlphaNumUtil::indent( trim($import_components_str), 1, $vue_indentation);
        $vue_components_str = AlphaNumUtil::indent( trim($vue_components_str), 3, $vue_indentation);

        //we use Fragment for Vue: https://www.npmjs.com/package/vue-fragment
        $compiled_hook_content = <<<CONTENT
<template>
    <fragment>
{$hooked_components_str}
    </fragment>
</template>

<script>
    //this is a generated file
    //see PHP composer package guzaba-platform/vue-component-hooks

{$import_components_str}
    export default {
        name: "{$content_component_name}",
        components: {
{$vue_components_str}
        },
    }
</script>

<style scoped>

</style>
CONTENT;

        file_put_contents($compiled_hook_path, $compiled_hook_content);
    }


    /**
     * @param string $component_hooks_dir
     * @throws InvalidArgumentException
     */
    private function set_component_hooks_dir(string $component_hooks_dir): void
    {
        $file_error = FilesUtil::file_error($component_hooks_dir, $is_writeable = true, $is_dir = true, $arg_name = 'component_hooks_dir');
        if ($file_error) {
            throw new InvalidArgumentException($file_error);
        }
        $this->component_hooks_dir = $component_hooks_dir;
    }

    /**
     * @param string $aliases_file
     * @throws InvalidArgumentException
     */
    private function set_aliases_file(string $aliases_file): void
    {
        $file_error = FilesUtil::file_error($aliases_file, $is_writeable = false, $is_dir = false, $arg_name = 'aliases_file');
        if ($file_error) {
            throw new InvalidArgumentException($file_error);
        }
        //let the JSON error bubble out
        $this->aliases = json_decode(file_get_contents($aliases_file), TRUE, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR);//the object is converted to associative array
        $this->aliases_file = $aliases_file;
    }

}