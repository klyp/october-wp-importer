<?php namespace Klyp\Wpimporter;

use Backend;
use Klyp\Wpimporter\Models\Wpimporter;
use System\Classes\PluginBase;
use System\Classes\PluginManager;

/**
 * Wpimporter Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * @var array Plugin dependencies
     *
     * Dependencies not required as plugins will be checked on the fly
     */
    public $require = [];
        
    /**
     * On Boot functions
     *
     * @return void
     */
    public function boot()
    {
        //Defaults
        $blogPost = '';
        $plugin = Wpimporter::getBlogVersionInstalled();

        //See which plugin is installed
        if ($plugin == 'Radiantweb.Problog') {
            $blogPost = 'Radiantweb\\Problog\\Models\\Post';
        } elseif ($plugin == 'RainLab.Blog') {
            $blogPost = 'RainLab\\Blog\\Models\\Post';
        }

        //If we have a plugin installed, then overwrite fillables
        if ($blogPost) {
            $blogPost::extend(function ($model) {
                $model->fillable(['user_id', 'title', 'slug', 'excerpt', 'content', 'published_at', 'published']);
            });
        }
    }

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'Wordpress Post Importer',
            'description' => 'Allows you to import Wordpress Posts into ProBlog plugin',
            'author'      => 'Klyp',
            'icon'        => 'icon-download'
        ];
    }

    /**
     * Returns settings menu items.
     *
     * @return array
     */
    public function registerSettings()
    {
        $plugin = Wpimporter::getBlogVersionInstalled();
        if ($plugin == 'Radiantweb.Problog') {
            return [
                'wpimportersettings' => [
                    'label'       => 'Wordpress Importer for ProBlog',
                    'description' => 'Import Wordpress posts into ProBlog.',
                    'icon'        => 'icon-download',
                    'class'       => 'Klyp\wpimporter\Models\Wpimporter',
                    'order'       => 1
                ]
            ];
        } elseif ($plugin == 'RainLab.Blog') {
            return [
                'wpimportersettings' => [
                    'label'       => 'Wordpress Importer for Blog',
                    'description' => 'Import Wordpress posts into Blog plugin.',
                    'icon'        => 'icon-download',
                    'class'       => 'Klyp\wpimporter\Models\Wpimporter',
                    'order'       => 1
                ]
            ];
        }
    }

    /**
     * Registers any front-end components implemented in this plugin.
     *
     * @return array
     */
    public function registerComponents()
    {
        return [];
    }

    /**
     * Registers any back-end permissions used by this plugin.
     *
     * @return array
     */
    public function registerPermissions()
    {
        //Access to Components
        return [
            'klyp.klyp.access_component_menu'   => ['tab' => 'Component Section', 'label' => 'Access to Component Section', 'order' => 800],
            'klyp.wpimporter.access_wpimporter' => ['tab' => 'Component Section', 'label' => 'Access to WP Importer', 'order' => 801],
        ];
    }

    /**
     * Registers back-end navigation items for this plugin.
     *
     * @return array
     */
    public function registerNavigation()
    {
        return [];
    }
}
