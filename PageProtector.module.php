<?php

/**
 * ProcessWire module to allow site editors to protect pages from guest access.
 * by Adrian Jones
 *
 * Allows site editors to protect pages from guest access.
 *
 *
 * Copyright (C) 2019 by Adrian Jones
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 */

class PageProtector extends WireData implements Module, ConfigurableModule {

    /**
     * Basic information about module
     */
    public static function getModuleInfo() {
        return array(
            'title' => 'Page Protector',
            'summary' => 'Allows site editors to protect pages from guest access.',
            'author' => 'Adrian Jones',
            'href' => 'http://modules.processwire.com/modules/page-protector/',
            'version' => '2.0.9',
            'autoload' => true,
            'singular' => true,
            'icon' => 'key',
            'permissions' => array(
                'page-edit-protected' => 'Access to set the protected status of pages (Page Protector Module)'
            )
        );
    }


    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();

    protected $protectOptions = array(
        "pid" => '',
        "page_protected" => false,
        "children" => false,
        "message_override" => "",
        "roles" => null,
        "prohibited_message" => "You do not have permission to view this page."
    );


   /**
     * Default configuration for module
     *
     */
    static public function getDefaultData() {
        return array(
            "protectSite" => 0,
            "protectHidden" => 0,
            "protectChildrenOfHidden" => 0,
            "protectUnpublished" => 0,
            "protectChildrenOfUnpublished" => 0,
            "protectedPages" => array(),
            "message" => "This page is protected. You must log in to view it.",
            "prohibited_message" => "You do not have permission to view this page.",
            "login_template" => "",
            "usernamePlaceholder" => "Username",
            "passwordPlaceholder" => "Password",
            "loginButtonText" => "Login",
            "logincss" => "
.page-protector-container {
    width: 400px;
    max-width: calc(100vw - 20px);
    height: 150px;
    margin: auto;
    position: absolute;
    top: 0;
    left: 0;
    bottom: 0;
    right: 0;
}

p, legend {
    font-family: Arial, Helvetica, sans-serif;
    display: block;
    width: 100%;
    margin-bottom: 1rem;
    color: #6F6F6F;
}

button {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 100%;
    padding: 0.5em 1em;
    background-color: #006DD3;
    color:#fff;
    text-decoration: none;
    border: 0 rgba(0,0,0,0);
    border-radius: 2px;
}
button:hover,
button:focus {
    background-color: #007DD2;
}
button:focus {
    outline: 0;
}

input[type='text'],
input[type='password'] {
    font-size: 100%;
    padding: 0.5rem;
    display: inline-block;
    border: 1px solid #ccc;
    box-shadow: inset 0 1px 3px #ddd;
    border-radius: 4px;
    -webkit-box-sizing: border-box;
    -moz-box-sizing: border-box;
    box-sizing: border-box;
}
"
            );
    }


    /**
     * Populate the default config data
     *
     */
    public function __construct() {
       foreach(self::getDefaultData() as $key => $value) {
               $this->$key = $value;
       }
    }


    /**
     * Initialize the module and setup hooks
     */
    public function init() {

        $this->wire()->addHook('Page::protect', $this, 'protectPages');

        $this->wire()->addHookProperty('Page::protected', $this, 'isProtected');
        $this->wire()->addHookProperty('Page::prohibited', $this, 'isProhibited');

        if($this->wire('user')->hasPermission("page-edit-protected")) {
            $this->wire()->addHookAfter('ProcessPageEdit::buildFormSettings', $this, 'buildPageProtectForm');
            $this->wire()->addHookAfter('ProcessPageEdit::processInput', $this, 'processPageProtectForm');
        }

        if(empty($this->data['protectedPages']) && !$this->data['protectUnpublished'] && !$this->data['protectHidden']) return; // no pages protected so can escape now

        // for unpublished pages need to hook before they get 404'd
        $this->wire()->addHookBefore('Page::viewable', $this, 'hookPageViewable');

        // if we get this far then add hook on page render to see if the page should be protected
        if($this->data['login_template'] != '') {
            $this->wire()->addHookBefore('PageRender::renderPage', $this, 'protectedCheck', array('priority'=>1000));
        }
        else {
            $this->wire()->addHookAfter('Page::render', $this, 'protectedCheck', array('priority'=>1000));
        }

    }


    public function hookPageViewable(HookEvent $event) {

        if(!$this->data['protectUnpublished']) return;

        $p = $event->object;

        // don't want this check in the admin, only on front-end
        if($this->wire('process') != 'ProcessPageView') return;

        $parentUnpublished = false;
        if($this->data['protectChildrenOfUnpublished']) {
            foreach($p->parents as $parent) {
                if($parent->hasStatus(Page::statusUnpublished)) {
                    $parentUnpublished = true;
                    break;
                }
            }
        }

        if($p->hasStatus(Page::statusUnpublished) || $parentUnpublished) {
            if(isset($this->data['protectedPages'][$p->id])) {
                $p->removeStatus(Page::statusUnpublished);
            }
        }
    }


    public function isProtected(HookEvent $event) {

        $p = $event->object;

        if($p->template == 'admin') return; // ignore admin pages including admin login page

        $protected = 0;
        $pagesToCheck = array();
        $pagesToCheck[] = $p->id; // include current page
        foreach ($p->parents as $parent) $pagesToCheck[] = $parent->id; // add all parents of current page

        foreach($pagesToCheck as $ptcid) {
            $ptc = $this->wire('pages')->get($ptcid);
            if(array_key_exists($ptc->id, $this->data['protectedPages']) || ($this->data['protectHidden'] && $ptc->hasStatus(Page::statusHidden) && $ptc->id != $this->wire('config')->http404PageID)) {
                // if we have a protected match for the current page or one of its parents have specified to protect its children
                if($p->id == $ptc->id || ($p->id != $ptc->id && isset($this->data['protectedPages'][$ptc->id]) && (isset($this->data['protectedPages'][$ptc->id]['children']) && $this->data['protectedPages'][$ptc->id]['children'] == 1)) || ($this->data['protectHidden'] && $this->data['protectChildrenOfHidden'] && $ptc->hasStatus(Page::statusHidden) && $ptc->id != $this->wire('config')->http404PageID)) {
                    $protected = $ptc->id;
                    break; // we have a match so no need to check other parents
                }
            }
        }
        $event->return = $protected != 0 ? $protected : false;
    }


    public function isProhibited(HookEvent $event) {

        $p = $event->object;
        if($p->template == 'admin') return; // ignore admin pages including admin login page
        if(isset($this->data['protectedPages']) && isset($this->data['protectedPages'][$p->protected]) && $this->data['protectedPages'][$p->protected]['roles'] !== null && !$this->wire('user')->roles->has("name=".implode("|",$this->data['protectedPages'][$p->protected]['roles']))) {
            $event->return = true;
        }
        else {
            return false;
        }
    }


    /**
     * Checks if page is protected and show login form if necessary
     *
     * @param HookEvent $event
     */
    public function protectedCheck(HookEvent $event) {

        // coming from PageRender::renderPage hook (when using custom template) vs Page::render with default login form
        $p = isset($event->arguments[0]->data['object']) ? $event->arguments[0]->data['object'] : $event->object;

        if($p->template == 'admin') return; // ignore admin pages including admin login page

        if($p->protected == 0) return; // if no matches, then escape now

        if($this->wire('languages')) {
            $userLanguage = $this->wire('user')->language;
            $lang = $userLanguage->isDefault() ? '' : "__$userLanguage->id";
        }
        else {
            $lang = '';
        }

        if($this->wire('user')->isLoggedin()) {
            if($p->protected && $p->prohibited) {
                if($this->data['login_template'] != '') {
                    $p->loginForm = "
                    <div class='page-protector-container'>
                        <p>" .
                            nl2br($this->getMessage($p, 'prohibited_message', $lang)) .
                        "</p>
                    </div>";
                    $p->template->filename = $this->wire('config')->paths->templates . $this->data['login_template'];
                }
                else {
                    // no template form provided so hijack page output and display basic html login form
                    $event->return = "
                    <!DOCTYPE html>
                        <head>
                            <meta charset='utf-8' />
                            <meta name='viewport' content='width=device-width, initial-scale=1'>
                            <style>
                                {$this->data['logincss']}
                            </style>
                        </head>
                        <body>
                            <div class='page-protector-container'>
                                <p>" .
                                    nl2br($this->getMessage($p, 'prohibited_message', $lang)) .
                                "</p>
                            </div>
                        </body>
                    </html>";
                }
            }
        }
        elseif($this->wire('input')->post->username && $this->wire('input')->post->pass) {
            $username = $this->wire('sanitizer')->username($this->wire('input')->post->username);
            $user = $this->wire('session')->login($username, $this->wire('input')->post->pass);
            if(!$user) $this->wire('session')->loginFailed = true;
            $this->wire('session')->redirect(htmlspecialchars($_SERVER['REQUEST_URI']));
        }
        else {
            $loginForm = "
                <style>
                    {$this->data['logincss']}
                </style>
                <form class='PageProtectorForm' action='".htmlspecialchars($_SERVER['REQUEST_URI'])."' method='post'>
                        <legend>" . ($this->wire('session')->loginFailed ? "<p>" . __("Login Failed, please try again!") . "</p>" : "") . nl2br($this->getMessage($p, 'message', $lang)) . "</legend>
                        <input type='text' name='username' placeholder='".($this->data['usernamePlaceholder'.$lang] ? $this->data['usernamePlaceholder'.$lang] : $this->data['usernamePlaceholder'])."' required />
                        <input type='password' name='pass' placeholder='".($this->data['passwordPlaceholder'.$lang] ? $this->data['passwordPlaceholder'.$lang] : $this->data['passwordPlaceholder'])."' required />
                        <p><button type='submit' name='login'>".($this->data['loginButtonText'.$lang] ? $this->data['loginButtonText'.$lang] : $this->data['loginButtonText'])."</button></p>
                </form>
            ";

            if($this->wire('session')->loginFailed) $this->wire('session')->loginFailed = false;

            if($this->data['login_template'] == '') {
                $event->return = "
                <!DOCTYPE html>
                    <head>
                        <meta charset='utf-8' />
                        <meta name='viewport' content='width=device-width, initial-scale=1'>
                    </head>
                    <body>
                        <div class='page-protector-container'>
                            $loginForm
                        </div>
                    </body>
                </html>
                ";
            }
            else {
                $p->loginForm = $loginForm;
                $p->template->filename = $this->wire('config')->paths->templates . $this->data['login_template'];
            }
        }
    }


    public function buildPageProtectForm(HookEvent $event) {

        $p = $event->object->getPage();

        $inputfields = $event->return;

        $fieldset = $this->wire('modules')->get("InputfieldFieldset");
        $fieldset->attr('id', 'protect_fieldset');
        if($p->protected != 0) {
            $fieldset->label = __("Page Protector (protected)");
        }
        else {
            $fieldset->label = __("Page Protector (not protected)");
        }
        $fieldset->collapsed = Inputfield::collapsedYes;

        if($p->protected != 0 && $p->id != $p->protected) {
            $f = $this->wire('modules')->get("InputfieldMarkup");
            $f->attr('name', 'already_protected');
            $f->label = __('Already Protected');
            $f->value = "
            <p>This page is already protected via its parent: " . $this->wire('pages')->get($p->protected)->title . "</p>
            <p>However, you can still apply more specific protection to certain roles with the settings below.</p>
            ";
            $fieldset->append($f);
        }

        $f = $this->wire('modules')->get('InputfieldCheckbox');
        $f->label = __('Protect this page');
        $f->description = __('If checked, front-end viewing of this page will be limited to logged in users with one of the selected roles.');
        $f->attr('name', 'page_protected');
        $f->attr('checked', isset($this->data['protectedPages']) && array_key_exists($p->id, $this->data['protectedPages']) ? 'checked' : '' );
        $fieldset->append($f);

        $f = $this->wire('modules')->get('InputfieldCheckbox');
        $f->attr('name', 'children');
        $f->label = __('Protect children');
        $f->showIf = "page_protected=1";
        $f->description = __('If checked, viewing of all children (and grandchildren etc) of this page will also be protected.');
        $f->attr('checked', isset($this->data['protectedPages'][$p->id]['children']) && $this->data['protectedPages'][$p->id]['children'] == 1 ? 'checked' : '' );
        $fieldset->append($f);

        $f = $this->wire('modules')->get("InputfieldTextarea");
        $f->attr('name', 'message_override');
        $f->label = __('Message');
        $f->useLanguages = true;
        $f->showIf = "page_protected=1";
        $f->description = __('This message will be displayed to users when they try to view the site.');
        $f->notes = __('This page specific message overrides the default one in the module config settings.');
        if($this->wire('languages')) {
            foreach($this->wire('languages') as $lang) {
                if($lang->isDefault()) continue;
                $value = isset($this->data['protectedPages'][$p->id]['message_override__' . $lang->id]) ? $this->data['protectedPages'][$p->id]['message_override__' . $lang->id] : (isset($this->data['message__' . $lang->id]) ? $this->data['message__' . $lang->id] : '');
                $f->set("value$lang->id", $value);
            }
        }
        $f->value = isset($this->data['protectedPages'][$p->id]['message_override']) ? $this->data['protectedPages'][$p->id]['message_override'] : $this->data['message'];
        $fieldset->add($f);

        $f = $this->wire('modules')->get("InputfieldAsmSelect");
        $f->name = 'roles';
        $f->label = 'Allowed Roles';
        $f->showIf = "page_protected=1";
        $f->description = __("To limit access to specific roles, select them here.\nTo allow all roles, leave none selected.");
        $f->notes = __("NB The users with these roles still need to log in to view the page.\nThis allows you to completely block the unselected roles from viewing the page, even if they are logged in.");
        foreach($this->wire('roles') as $role) {
            $f->addOption($role->name, $role->name);
            if(isset($this->data['protectedPages'][$p->id]['roles']) && in_array($role->name, $this->data['protectedPages'][$p->id]['roles'])) $f->attr('value', $role->name);
        }
        $f->setAsmSelectOption('sortable', false);
        $fieldset->append($f);


        $f = $this->wire('modules')->get("InputfieldTextarea");
        $f->attr('name', 'prohibited_message');
        $f->label = __('Prohibited Message');
        $f->useLanguages = true;
        $f->showIf = "page_protected=1";
        $f->description = __('This message will be displayed to logged in users when they try to view a page that their role doesn\'t have permission to view.');
        if($this->wire('languages')) {
            foreach($this->wire('languages') as $lang) {
                if($lang->isDefault()) continue;
                $value = isset($this->data['protectedPages'][$p->id]['prohibited_message__' . $lang->id]) ? $this->data['protectedPages'][$p->id]['prohibited_message__' . $lang->id] : (isset($this->data['prohibited_message__' . $lang->id]) ? $this->data['prohibited_message__' . $lang->id] : '');
                $f->set("value$lang->id", $value);
            }
        }
        $f->value = isset($this->data['protectedPages'][$p->id]['prohibited_message']) ? $this->data['protectedPages'][$p->id]['prohibited_message'] : $this->data['prohibited_message'];
        $fieldset->add($f);


        $inputfields->append($fieldset);

    }

    public function protectPages(HookEvent $event) {
        $p = $event->object;
        $options = $event->arguments(0);

        $options = array_merge($this->protectOptions, $options);
        $options['pid'] = $p->id;
        if($options['message_override'] == '') $options['message_override'] = $this->data['message'];

        $this->saveSettings($options);
    }


    public function processPageProtectForm(HookEvent $event) {

        // ProcessPageEdit's processInput function may go recursive, so we want to skip
        // the instances where it does that by checking the second argument named "level"
        $level = $event->arguments(1);
        if($level > 0) return;

        $p = $event->object->getPage();
        if($p->matches("has_parent={$this->wire('config')->adminRootPageID}|{$this->wire('config')->trashPageID}")) return;

        $options = array(
            "pid" => $p->id,
            "page_protected" => $this->wire('input')->post->page_protected,
            "children" => $this->wire('input')->post->children,
            "roles" => $this->wire('input')->post->roles,
            "message_override" => $this->wire('input')->post->message_override,
            "prohibited_message" => $this->wire('input')->post->prohibited_message
        );

        if($this->wire('languages')) {
            foreach($this->wire('languages') as $lang) {
                if(!$lang->isDefault()) {
                    $textField = 'message_override__'.$lang->id;
                    $options['message_override__'.$lang->id] = $this->wire('input')->post->$textField;
                    $textField = 'prohibited_message__'.$lang->id;
                    $options['prohibited_message__'.$lang->id] = $this->wire('input')->post->$textField;
                }
            }
        }

        $options = array_merge($this->protectOptions, $options);

        // create array of options and remove two keys so it can be compared to existing settings for this page
        // if they don't match existing settings, or no settings exist, then save settings
        $optionsToCompare = $options;
        unset($optionsToCompare['pid']);
        unset($optionsToCompare['page_protected']);

        if(
            (isset($this->data['protectedPages'][$p->id]) && !$options['page_protected']) ||
            (!isset($this->data['protectedPages'][$p->id]) && $options['page_protected']) ||
            (isset($this->data['protectedPages'][$p->id]) && $this->data['protectedPages'][$p->id] != $optionsToCompare)
        ) {
                $this->saveSettings($options);
            }
    }

    public function ___getMessage($p, $textField, $lang) {

        $text = '';
        $override = $textField == 'message' ? '_override' : '';

        if($lang == '') {
            $text = isset($this->data['protectedPages'][$p->protected][$textField.$override]) ? $this->data['protectedPages'][$p->protected][$textField.$override] : $this->data[$textField];
        }
        else {
            if(isset($this->data['protectedPages'][$p->protected][$textField.$override.$lang])) {
                $text = $this->data['protectedPages'][$p->protected][$textField.$override.$lang];
            }
            if(!$text) {
                $text = isset($this->data[$textField.$lang]) ? $this->data[$textField.$lang] : $this->data['protectedPages'][$p->protected][$textField.$override];
            }
            if(!$text) {
                $text = $this->data[$textField];
            }
        }

        return $text;
    }

    public function saveSettings($options) {
        $pid = $options['pid'];
        unset($this->data['protectedPages'][$pid]); // remove existing record for this page - need a clear slate for adding new settings or if it was just disabled
        if((int) $options['page_protected'] == 1) {
            $this->data['protectedPages'][$pid]['children'] = $options['children'];
            $this->data['protectedPages'][$pid]['roles'] = $options['roles'];

            if($this->wire('languages')) {
                foreach($this->wire('languages') as $lang) {
                    if(!$lang->isDefault()) {
                        $this->data['protectedPages'][$pid]['message_override__'.$lang->id] = $options['message_override__'.$lang->id];
                        $this->data['protectedPages'][$pid]['prohibited_message__'.$lang->id] = $options['prohibited_message__'.$lang->id];
                    }
                }
            }
            $this->data['protectedPages'][$pid]['message_override'] = $options['message_override'];
            $this->data['protectedPages'][$pid]['prohibited_message'] = $options['prohibited_message'];
        }

        // save to config data with the rest of the settings
        $this->wire('modules')->saveModuleConfigData($this, $this->data);
    }

    /**
     * Return an InputfieldsWrapper of Inputfields used to configure the class
     *
     * @param array $data Array of config values indexed by field name
     * @return InputfieldsWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $this->wire('config')->styles->add($this->wire('config')->urls->PageProtector . "PageProtector.css");

        $data = array_merge(self::getDefaultData(), $data);

        if($data['protectSite'] && (!isset($data['protectedPages'][1]) || $data['protectedPages'][1]['children'] == null)) {
            unset($data['protectedPages'][1]); // remove existing record for this page - need a clear slate for adding new settings or if it was just disabled
            $data['protectedPages'][1]['children'] = 1;
            $data['protectedPages'][1]['message_override'] = $data['message'];
            $data['protectedPages'][1]['roles'] = null;
            if($data['login_template'] != '') $data['protectedPages'][1]['prohibited_message'] = $data['prohibited_message'];
        }

        unset($data['protectSite']);

        // save to config data with the rest of the settings
        $this->wire('modules')->saveModuleConfigData($this, $data);



        $wrapper = new InputfieldWrapper();

        $f = $this->wire('modules')->get("InputfieldMarkup");
        $f->attr('name', 'instructions');
        $f->label = __('Instructions');
        $f->value = "";
        if($this->wire('modules')->isInstalled("ProtectedMode")) $f->value .= "<p style='color:#990000'>You also have the ProtectedMode module installed. This module provides all the functionality of ProtectedMode, so it should be uninstalled when running this module.</p>";
        $f->value .= "
        <p>Go to the settings tab of a page and adjust the \"Protect this Page\" settings.";
        if(!isset($data['protectedPages'][1]) || !isset($data['protectedPages'][1]['children'])) {
            $f->value .= "To protect the entire site, go to the homepage and also check \"Protect Children\", or use the \"Protect Entire Site\" shortcut below.";
        }
        $f->value .= "</p>
        <p>You can also limit logged in access to certain roles by choosing those roles when editing the \"Protect this Page\" settings.</p>
        <p>To give your site editors the ability to protect pages, you need to give them the \"page-edit-protected\" permission.</p>
        <p>If you want non-admin visitors to view protected pages you should create a new generic user with only the guest role and provide them with those login details.</p>
        ";
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldMarkup");
        $f->attr('name', 'table');
        $f->label = __('Protected Pages');
        $value = '';

        if(!empty($data['protectedPages'])) {
            $protectedPagesCount = $this->wire('pages')->count('include=all, id='.implode('|', array_keys($data['protectedPages'])));
        }
        else {
            $protectedPagesCount = 0;
        }

        if($protectedPagesCount === 0) {
            $value .= "<p style='color:#990000'>Currently no individual pages are protected. This does not include Hidden and Unpublished pages protected by the options below.</p><p style='color:#990000'>To protect pages, you need to specify the pages, and optionally their children, to be protected from each page's Settings tab.</p>";
        }
        else {
            $value .= "<p style='color:#009900'>Currently there " . ($protectedPagesCount >1 ? " are " : " is ") . $protectedPagesCount." protected parent page" . ($protectedPagesCount >1 ? "s" : "") . "</p>";
        }

        if($protectedPagesCount > 0) {
            $table = $this->wire('modules')->get("MarkupAdminDataTable");
            $table->setEncodeEntities(false);
            $table->setSortable(false);
            $table->setClass('pageprotector');
            $table->headerRow(array(
                __('ID'),
                __('Title'),
                __('Path'),
                __('Children'),
                __('Allowed Roles'),
                __('Edit'),
                __('View')
            ));

            foreach($data['protectedPages'] as $id => $details) {
                $p = $this->wire('pages')->get($id);
                if($p->id) {
                    $row = array(
                        $id,
                        $p->title,
                        $p->path,
                        (isset($details['children']) && $details['children'] == 1 ? 'Yes' : 'No'),
                        (!empty($details['roles']) ? implode(", ", $details['roles']) : 'ALL'),
                        '<a href="'.$this->wire('config')->urls->admin.'page/edit/?id='.$id.'#ProcessPageEditSettings">edit</a>',
                        '<a href="'.$p->url.'">view</a>'
                    );
                    $table->row($row);
                }
            }
            $value .= $table->render();

        }

        $f->attr('value', $value);
        $wrapper->add($f);


        if(!isset($data['protectedPages'][1]) || !isset($data['protectedPages'][1]['children'])) {
            $f = $this->wire('modules')->get("InputfieldCheckbox");
            $f->attr('name', 'protectSite');
            $f->label = __('Protect Entire Site');
            $f->description = __("This is a shortcut to protect the entire website - it will automatically protect the homepage and its children with no role restrictions.");
            $f->attr('checked', isset($data['protectSite']) && $data['protectSite'] ? 'checked' : '' );
            $wrapper->add($f);
        }
        else {
            $f = $this->wire('modules')->get("InputfieldHidden");
            $f->attr('name', 'protectSite');
            $f->value = 0;
            $wrapper->add($f);
        }

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'protectUnpublished');
        $f->label = __('Protect Unpublished Pages');
        $f->description = __("If checked, all pages with an unpublished status will be protected.");
        $f->note = __("Without this, unpublished pages would return a 404. This instead provides the login so that authorized users can then view the pages.");
        $f->columnWidth = 50;
        $f->attr('checked', isset($data['protectUnpublished']) && $data['protectUnpublished'] ? 'checked' : '' );
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'protectChildrenOfUnpublished');
        $f->showIf="protectUnpublished=1";
        $f->label = __('Protect Children of Unpublished Pages');
        $f->description = __("If checked, all children of a page with an unpublished status will be protected.");
        $f->columnWidth = 50;
        $f->attr('checked', isset($data['protectChildrenOfUnpublished']) && $data['protectChildrenOfUnpublished'] ? 'checked' : '' );
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'protectHidden');
        $f->label = __('Protect Hidden Pages');
        $f->description = __("If checked, all pages with a hidden status will be protected.");
        $f->columnWidth = 50;
        $f->attr('checked', isset($data['protectHidden']) && $data['protectHidden'] ? 'checked' : '' );
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldCheckbox");
        $f->attr('name', 'protectChildrenOfHidden');
        $f->showIf="protectHidden=1";
        $f->label = __('Protect Children of Hidden Pages');
        $f->description = __("If checked, all children of a page with a hidden status will be protected.");
        $f->columnWidth = 50;
        $f->attr('checked', isset($data['protectChildrenOfHidden']) && $data['protectChildrenOfHidden'] ? 'checked' : '' );
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldTextarea");
        $f->attr('name', 'message');
        $f->label = __('Message');
        $f->useLanguages = true;
        $f->description = __('This message will be displayed to users when they try to view a protected page.');
        $f->value = $data['message'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'usernamePlaceholder');
        $f->label = __('Username Placeholder');
        $f->useLanguages = true;
        $f->description = __('By default this will be "Username".');
        $f->value = $data['usernamePlaceholder'];
        $f->columnWidth = 33;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'passwordPlaceholder');
        $f->label = __('Password Placeholder');
        $f->useLanguages = true;
        $f->description = __('By default this will be "Password".');
        $f->value = $data['passwordPlaceholder'];
        $f->columnWidth = 34;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldText");
        $f->attr('name', 'loginButtonText');
        $f->label = __('Login Button Text');
        $f->useLanguages = true;
        $f->description = __('By default this will be "Login".');
        $f->value = $data['loginButtonText'];
        $f->columnWidth = 33;
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldTextarea");
        $f->attr('name', 'prohibited_message');
        $f->label = __('Default Prohibited Message');
        $f->useLanguages = true;
        $f->description = __('This is the default prohibited message if you protect a page from viewing by certain user roles.');
        $f->notes = __('Prohibiting page access by user role can only be done from the Settings tab of a page. This config setting is just a convenience for setting a default for your site.');
        $f->value = $data['prohibited_message'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldTextarea");
        $f->attr('name', 'logincss');
        $f->label = __('CSS');
        $f->description = __("You can change the style of the login form here.");
        $f->value = $data['logincss'];
        $wrapper->add($f);

        $f = $this->wire('modules')->get("InputfieldSelect");
        $f->name = 'login_template';
        $f->label = 'Login Template';
        $f->description = __('This is optional! It allows you to embed the login form within your site, rather than showing the login form on its own on a blank page. The login form will be inserted into the selected template. You must output `$page->loginForm` somewhere in the template, like:```'."\n\n".'include("./head.inc");'."\n".'echo $page->loginForm;'."\n".'include("./foot.inc");```');
        $f->notes = __("This template does not need to be defined in PW - just a file is sufficient. It is a dedicated file just for the login form.");
        foreach($iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->wire('config')->paths->templates, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if (!$item->isDir() && in_array(pathinfo($item, PATHINFO_EXTENSION), array($this->wire('config')->templateExtension, 'php', 'inc'))) {
                $f->addOption($iterator->getSubPathName(), $iterator->getSubPathName());
            }
        }
        $f->value = $data['login_template'];
        $wrapper->add($f);

        return $wrapper;
    }

}
