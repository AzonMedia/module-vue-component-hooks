<?php
declare(strict_types=1);

namespace Azonmedia\VueComponentHooks;

use Azonmedia\Exceptions\InvalidArgumentException;
use Azonmedia\Exceptions\RunTimeException;
use Azonmedia\Translator\Translator as t;
use Azonmedia\Utilities\FilesUtil;
use Composer\Package\Package;

class VueComponentHooks
{

    /**
     * @var string
     */
    private string $component_hooks_dir;

    private string $aliases_file;

    private array $aliases = [];

    private array $hooks = [];

    /**
     * VueComponentHooks constructor.
     * @param string $component_hooks_dir
     * @param string $aliases_file Path to json file containing aliases used by webpack
     */
    public function __construct(string $component_hooks_dir, string $aliases_file = '')
    {
        if ($aliases_file) {
            $this->set_aliases_file($aliases_file);
        }
        $this->set_component_hooks_dir($component_hooks_dir);

    }

    public function get_component_hooks_dir(): string
    {
        return $this->component_hooks_dir;
    }

    public function get_aliases_file(): string
    {
        return $this->aliases_file;
    }

    public function get_aliases(): array
    {
        return $this->aliases;
    }

    /**
     * @example $FrontendHooks->add('@GuzabaPlatform.Navigation/components/AddLink.vue','_after_tabs','@GuzabaPlatform.Cms/components/hooks/AddLinkPage.vue');
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

    public function remove(): void
    {

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
     * @param string $hooked_component_name
     * @return bool
     */
    public function component_file_exists(string $component_file, ?string &$_file_error = null): bool
    {
        $component_file = $this->resolve_component_name($component_file);
        $_file_error = FilesUtil::file_error($component_file, $is_writeable = false, $is_dir = false, 'component_name');
        return $_file_error ? false : true ;
    }

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

    //public function get_component_hooks(string $component_name)
    public function dump_hooks(): void
    {

    }

    protected function dump_hook(string $component_name, string $hook_name, string $hooked_component_name): void
    {

    }



    private function set_component_hooks_dir(string $component_hooks_dir): void
    {
        $file_error = FilesUtil::file_error($component_hooks_dir, $is_writeable = true, $is_dir = true, $arg_name = 'component_hooks_dir');
        if ($file_error) {
            throw new InvalidArgumentException($file_error);
        }
        $this->component_hooks_dir = $component_hooks_dir;
    }

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