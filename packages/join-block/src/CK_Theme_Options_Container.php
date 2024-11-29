<?php

namespace CommonKnowledge\JoinBlock;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use Carbon_Fields\Carbon_Fields;
use Carbon_Fields\Container\Theme_Options_Container;
use Carbon_Fields\Exception\Incorrect_Syntax_Exception;
use Carbon_Fields\Helper\Helper;

/**
 * Add custom capabilities to the default Theme_Options_Container, such as:
 * - adding custom error messages
 * - preventing clearing invalid submitted data in to_json(),
 *   so the user can re-use it when they resubmit the form
 */
class CK_Theme_Options_Container extends Theme_Options_Container
{
    /**
     * This function copied-and-pasted, but modified to return an instance 
     * of CK_Theme_Options_Container instead of the parent class.
     */
    public static function make()
    {
        list($raw_type, $id, $name) = func_get_args();
        // no name provided - switch input around as the id is optionally generated based on the name
        if ($name === '') {
            $name = $id;
            $id = '';
        }

        $type = Helper::normalize_type($raw_type);
        $repository = Carbon_Fields::resolve('container_repository');
        $id = $repository->get_unique_container_id(($id !== '') ? $id : $name);

        if (! Helper::is_valid_entity_id($id)) {
            Incorrect_Syntax_Exception::raise('Container IDs can only contain lowercase alphanumeric characters, dashes and underscores ("' . $id . '" passed).');
            return null;
        }

        if (! $repository->is_unique_container_id($id)) {
            Incorrect_Syntax_Exception::raise('The passed container id is already taken ("' . $id . '" passed).');
            return null;
        }

        $container = null;
        if (Carbon_Fields::has($type, 'containers')) {
            $container = Carbon_Fields::resolve_with_arguments($type, array(
                'id' => $id,
                'name' => $name,
                'type' => $type,
            ), 'containers');
        } else {
            // Note: this is the key change
            $class = CK_Theme_Options_Container::class;
            if (!class_exists($class)) {
                Incorrect_Syntax_Exception::raise('Unknown container "' . $raw_type . '".');
                $class = __NAMESPACE__ . '\\Broken_Container';
            }
            $fulfillable_collection = Carbon_Fields::resolve('container_condition_fulfillable_collection');
            $condition_translator = Carbon_Fields::resolve('container_condition_translator_json');
            $container = new $class($id, $name, $type, $fulfillable_collection, $condition_translator);
        }

        $repository->register_container($container);
        return $container;
    }

    public function add_errors($errors)
    {
        $this->errors = array_merge($this->errors, $errors);
    }

    /**
     * Prevent re-loading values from the database when rendering the container
     * data to the front-end. This is necessary for the form displayed when
     * validation fails to retain the submitted values, so the user doesn't
     * have to put them in again to re-submit.
     */
    public function to_json($load)
    {
        $load = $load && !$this->errors;
        return parent::to_json($load);
    }
}
