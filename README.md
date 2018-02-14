# Page Protector

Processwire module to allow site editors to protect pages from guest access.

### Functionality

* Ability for your site editors to control the user access to pages directly from Settings tab of each page
* Include whether to protect all children of this page or not
* Optionally allow access to only specified roles
* Option to protect all hidden pages (and optionally their children)
* Ability to change the message on the login page to make it specific to this page
* Option to have login form and prohibited message injected into a custom template
* Access to the "Protect this Page" settings panel is controlled by the "page-edit-protected" permission
* Table in the module config settings that lists the details all of the protected pages
* Shortcut to protect entire site with one click

### API method
You can make changes to the protection settings of a page via the API

```
// all optional, except "page_protected", which must be set to true/false
// if setting it to false, the other options are not relevant

$options = array(
    "page_protected" => true,
    "children_protected" => true,
    "allowed_roles" => array("role1", "role2"),
    "message_override" => "My custom login message",
    "prohibited_message" => "My custom prohibited access message"
);

$page->protect($options);
```

You can also check the status of a page with:
```
$page->protected
$page->prohibited
```

#### Support forum:
https://processwire.com/talk/topic/8387-page-protector/

## License

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.

(See included LICENSE file for full license text.)