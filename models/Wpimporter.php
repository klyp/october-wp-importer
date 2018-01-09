<?php namespace Klyp\Wpimporter\Models;

use Backend\Models\User;
use Cms\Classes\Page;
use DB;
use File;
use Flash;
use Hash;
use Markdown;
use Model;
use October\Rain\Support\ValidationException;
use Storage;
use Str;
use System\Classes\PluginManager;
use System\Models\File as FileModel;

/**
 * Importer Model
 */
class Wpimporter extends Model
{
     use \October\Rain\Database\Traits\Validation;

    /**
     *  Implements site-wide settings
     */
    public $implement = ['System.Behaviors.SettingsModel'];
    public $settingsCode = 'klyp_wpimporter_setting';
    public $settingsFields = 'fields.yaml';

    public $importNowFlag;

    /**
     * Validations
     *
     * @var array
     */
    public $rules = [
        'import_xml_file' => 'required'
    ];

    /**
     * Relations
     *
     * @var array
     */
    public $attachOne = [
        'import_xml_file' => ['System\Models\File']
    ];

    /**
     * Get Blog Version Attribute
     * @return string
     */
    public function getBlogVersionAttribute()
    {
        return $this->getBlogVersionInstalled();
    }

    /**
     * Get list of pages for parent page
     * @return array
     */
    public function getParentPageOptions()
    {
        return Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
    }

    /**
     * Get list of admins
     * @return array
     */
    public function getDefaultAdminOptions()
    {
        return User::lists('login', 'id');
    }

    /**
     * Get which version of blog plugin installed
     *
     * @return string
     */
    public static function getBlogVersionInstalled()
    {
        $plugins = array_keys(PluginManager::instance()->getPlugins());
        if (in_array('Radiantweb.Problog', $plugins)) {
            return 'Radiantweb.Problog';
        } elseif (in_array('RainLab.Blog', $plugins)) {
            return 'RainLab.Blog';
        } else {
            return;
        }
    }

    /**
     * After validation
     * @return [type] [description]
     */
    public function afterValidate()
    {
        $this->importNowFlag = $this->import_xml_now;
        $this->import_xml_now = 'no';
        //Make sure blog version is entered correctly
        $this->blog_version = $this->getBlogVersionInstalled();
    }

    /**
     * After update see if import is selected
     *
     * @return
     */
    public function afterSave()
    {
        if ($this->importNowFlag == 'yes') {
            $this->onDoImport();
        }
    }

    /**
     * Function to import
     * @return array
     */
    public function onDoImport()
    {
        $blogVersion = Wpimporter::get('blog_version');
        if ($blogVersion == 'Radiantweb.Problog') {
            $blogCategory = 'Radiantweb\\Problog\\Models\\Category';
            $blogPost = 'Radiantweb\\Problog\\Models\\Post';
            $blogTag = 'Radiantweb\\Problog\\Models\\Tag';
        } elseif ($blogVersion == 'RainLab.Blog') {
            $blogCategory = 'RainLab\\Blog\\Models\\Category';
            $blogPost = 'RainLab\\Blog\\Models\\Post';
        } else {
            Flash::error('Errors encountered - no blog plugin detected, please make sure you have either the blog or problog plugin installed before you can use the importer.');
            exit();
        }

        //Default count
        $countError = 0;
        $countImport = 0;
        $tempFolder = 'storage/app/uploads/public/';

        if (! empty($this->import_xml_file)) {
            set_time_limit(360);
            $importFileContent = $this->import_xml_file()->withDeferred(input('_session_key'))->first()->getContents();

            //Defaults
            $replaceArray = Wpimporter::get('replace_array');
            $defaultParentPage = Wpimporter::get('parent_page');
            $defaultAdminId = Wpimporter::get('default_admin');
            $defaultPostStatus = Wpimporter::get('post_status');
            $attachments = array();
            $replaceFrom = [];
            $replaceTo = [];

            foreach ($replaceArray as $key) {
                $replaceFrom[] = $key['replace_from'];
                $replaceTo[] = $key['replace_with'];
            }

            //replace from to
            $importFile = str_replace($replaceFrom, $replaceTo, $importFileContent);
            $xmls = simplexml_load_string($importFile);

            foreach ($xmls->channel->item as $item) {
                try {
                    //Get item/node
                    $namespaces = $item->getNameSpaces(true);
                    $wpChildren = $item->children($namespaces['wp']);
                    $dcChildren = $item->children($namespaces['dc']);
                    $contentChildren = $item->children($namespaces['content']);
                    $excerptChildren = $item->children($namespaces['excerpt']);

                    //We grab all featured images just in case
                    if ($wpChildren->post_type == 'attachment' && $wpChildren->post_parent > 0) {
                        $attachments['parentId-'.$wpChildren->post_parent] = $wpChildren->attachment_url;
                    }

                    //We only import post - ignore the rest (eg. attachment/pages/or other postypes)
                    if ($wpChildren->post_type == 'post' && ! empty($item->title)) {
                        //If all or publish only
                        if ($defaultPostStatus == 'all' || $wpChildren->status == $defaultPostStatus) {
                            //Insert post
                            $postBlog = $blogPost::where('title', '=', $item->title)->first();

                            //If post doesn't exist then create (to avoid rules for blog plugin)
                            if (! $postBlog) {
                                $postBlog = $blogPost::create(['title' => $item->title, 'slug' => Str::slug($item->title), 'content' => '&nbsp;']);
                            }

                            //Get category tags
                            for ($i=0; $i<count($item->category); $i++) {
                                foreach ($item->category[$i]->attributes() as $key => $value) {
                                    if ($value == 'category') {
                                        //Insert category
                                        $postCategory = $blogCategory::firstOrCreate(['name' => $item->category[$i]]);
                                        $postCategory->slug = Str::slug($item->category[$i]);
                                        $postCategory->save();

                                        // If Pro Blog
                                        if ($blogVersion == 'Radiantweb.Problog') {
                                            //Insert into pivot table - since the plug has the pivot table but no relationship is set up... duh?
                                            $postCatTbl = DB::table('radiantweb_blog_post_categories')->where([
                                                'post_id' => $postBlog->id,
                                                'categories_id' => $postCategory->id
                                            ])->get();

                                            //If doesn't exist, then insert
                                            if (! $postCatTbl) {
                                                $postCatTbl = DB::table('radiantweb_blog_post_categories')->insert([
                                                    'post_id' => $postBlog->id,
                                                    'categories_id' => $postCategory->id
                                                ]);
                                            }

                                        } elseif ($blogVersion == 'RainLab.Blog') {
                                            //Detach if exist
                                            $postCategory->posts()->detach($postBlog->id);
                                            //Attach category to post
                                            $postCategory->posts()->attach($postBlog->id);
                                        }

                                    } elseif ($value == 'post_tag' && $blogVersion == 'Radiantweb.Problog') {
                                        //Insert tag
                                        $postTag = $blogTag::firstOrCreate(['name' => $item->category[$i]]);
                                        $postTag->slug = Str::slug($item->category[$i]);
                                        $postTag->save();

                                        //Detach if exist
                                        $postBlog->tags()->detach($postTag->id);
                                        //Attach tags to post
                                        $postBlog->tags()->attach($postTag->id);
                                    }
                                }
                            }

                            //Get admin by login
                            $userId = User::where('login', '=', $dcChildren->creator)->first();
                            if ($userId) {
                                $postBlog->user_id = $userId->id;
                            } else {
                                $postBlog->user_id = $defaultAdminId;
                            }

                            //Only for pro
                            if ($blogVersion == 'Radiantweb.Problog') {
                                $postBlog->categories_id = $postCategory->id;
                                $postBlog->parent = $defaultParentPage;
                                $postBlog->content = Markdown::parse(trim($contentChildren->encoded));
                            } elseif ($blogVersion == 'RainLab.Blog') {
                                $postBlog->content = $contentChildren->encoded;
                            }
                            $postBlog->title = $item->title;

                            //If post name empty for slug
                            if (!empty((string)$wpChildren->post_name)) {
                                $postBlog->slug = (string)$wpChildren->post_name; 
                            } else { 
                                $postBlog->slug = Str::slug((string)$item->title);
                            }
                            $postBlog->excerpt = $excerptChildren->encoded;
                            $postBlog->published_at = $wpChildren->post_date;
                            
                            //Post status
                            if ($wpChildren->status == 'publish') {
                                $postBlog->published = 1;
                            } else {
                                $postBlog->published = 0;
                            }
                            
                            //Save
                            $postBlog->save();

                            //Get featured image if exists
                            if (isset($attachments['parentId-'.$wpChildren->post_id]) && ! empty($attachments['parentId-'.$wpChildren->post_id])) {
                                $attachmentImage = $attachments['parentId-'.$wpChildren->post_id];
                                $fileContents = $this->downloadFileCurl($attachmentImage);
                                if ($fileContents) {
                                    $fileName = basename($attachmentImage);
                                    $fileExt = File::extension($attachmentImage);

                                    $hash = md5($fileName. '!' .str_random(40)); //need to randomize filename incase file exists
                                    $diskName = base64_encode($fileName. '!' .$hash).'.'.$fileExt;
                                    //Write it to temp storage
                                    $fileTemp = $tempFolder.$diskName;
                                    File::put($fileTemp, $fileContents);
                                    $uploadFolders = $this->generateHashedFolderName($diskName);
                                    $uploadFolder = $tempFolder.$uploadFolders[0].'/'.$uploadFolders[1].'/'.$uploadFolders[2];
                                    File::makeDirectory($uploadFolder, 0755, true, true);

                                    $fileMime = File::mimeType($fileTemp);
                                    $fileSize = File::size($fileTemp);

                                    $fileNew = $uploadFolder.'/'.$diskName;
                                    if (File:: move($fileTemp, $fileNew)) {
                                        //Save to db
                                        $postFeaturedImage = new FileModel;
                                        $postFeaturedImage->disk_name = $diskName;
                                        $postFeaturedImage->file_name = $fileName;
                                        $postFeaturedImage->file_size = $fileSize;
                                        $postFeaturedImage->content_type = $fileMime;
                                        $postFeaturedImage->field = 'featured_images';
                                        $postFeaturedImage->attachment_id = $postBlog->id;
                                        if ($blogVersion == 'Radiantweb.Problog') {
                                            $postFeaturedImage->attachment_type = 'Radiantweb\Problog\Models\Post';
                                        } elseif ($blogVersion == 'RainLab.Blog') {
                                            $postFeaturedImage->attachment_type = 'RainLab\Blog\Models\Post';
                                        }
                                        $postFeaturedImage->is_public = 1;
                                        $postFeaturedImage->sort_order = 1;
                                        $postFeaturedImage->save();
                                    }
                                }
                            }

                            //Count imported
                            $countImport++;
                        }
                    }

                } catch (Exception $ex) {
                    $countError++;
                }
            }
        }
        Flash::success($countImport . ' number of posts imported. And there were '. $countError . ' errors encountered.');
    }

    /**
     * Generate hashed folder name from filename
     *
     * @param  string
     * @return array
     */
    public static function generateHashedFolderName($filename)
    {
        $folderName[] = substr($filename, 0, 3);
        $folderName[] = substr($filename, 3, 3);
        $folderName[] = substr($filename, 6, 3);
        return $folderName;
    }

    /**
     * Grab image from url
     * @param  string
     * @return array
     */
    public function downloadFileCurl($url)
    {
        set_time_limit(360);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_POST, 0);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $fileContent = curl_exec($ch);
        curl_close($ch);
        
        if ($fileContent) {
            return $fileContent;
        } else {
            return false;
        }
    }
}
