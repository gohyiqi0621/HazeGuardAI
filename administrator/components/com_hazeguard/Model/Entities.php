<?php
declare(strict_types=1);

namespace HazeGuard\Component\Hazeguard\Administrator\Model;

/**
 * The three things an administrator manages, described as data.
 *
 * Every panel has the same shape — list, form, detail, delete — so that shape is
 * written once (AdminModel + tmpl/dashboard/panel.php) and each entity only
 * declares what makes it different: its table, its fields, and the foreign keys
 * it points at or is pointed at by.
 *
 * Table names, column names and labels below are literals from this file. No
 * value here ever comes from request input.
 *
 * Field keys:
 *   label       heading shown in the form and the list
 *   type        text | email | password | select | fk
 *   column      database column, when it differs from the field key
 *   required    must not be empty
 *   unique      the database has a UNIQUE key on it
 *   max         maximum length in characters
 *   validate    extra rule: username | email | password | slug
 *   options     value => label, for a select whose choices are a fixed list
 *   ref         foreign key target:
 *                 table   what the stored value is checked against
 *                 label   the column to display
 *                 order   dropdown sort order (default `label ASC`)
 *   hash        hash the value before storing it (passwords)
 *   secret      mask the value in the list
 *   encrypted   encrypt the value before storing it, and decrypt it on read.
 *               See Crypto: AES-256-GCM, so the column holds ciphertext and the
 *               key itself lives in .env rather than the database.
 *   hint        one line of help under the input
 *
 * Entity keys:
 *   referenced child tables that BLOCK a delete (ON DELETE RESTRICT)
 */
final class Entities
{
    /**
     * The sidebar, built from the specs so a panel's label is written once:
     * the overview first, then every entity under its own group.
     *
     * @return array<string,array<string,string>> group => (view key => label)
     */
    public static function menu(): array
    {
        $menu = ['Overview' => ['dashboard' => 'Dashboard']];

        foreach (self::all() as $key => $spec) {
            $menu[(string) $spec['group']][$key] = (string) $spec['title'];
        }

        return $menu;
    }

    /**
     * The tabs across the top of a panel: the other entities in the same group,
     * in declaration order.
     *
     * User and Usergroup share the "Members" group, so they render as the tabs
     * of the member management screen. A group holding a single entity returns
     * nothing — a one-item tab row would be furniture.
     *
     * @return array<string,string> view key => label; empty when there is no tab row
     */
    public static function tabsFor(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            return [];
        }

        $group = (string) $all[$key]['group'];
        $tabs  = [];

        foreach ($all as $tabKey => $spec) {
            if ((string) $spec['group'] === $group) {
                $tabs[$tabKey] = (string) $spec['title'];
            }
        }

        return count($tabs) > 1 ? $tabs : [];
    }

    public static function has(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    /** @return array<string,mixed> */
    public static function get(string $key): array
    {
        $all = self::all();
        if (!isset($all[$key])) {
            throw new \InvalidArgumentException("Unknown admin entity: {$key}");
        }

        return $all[$key];
    }

    /**
     * Declaration order is display order: it drives the sidebar, the tabs and
     * the dashboard tiles, so there is no second list to keep in step.
     *
     * @return array<string,array<string,mixed>> entity key => spec
     */
    public static function all(): array
    {
        return [
            // ---------------------------------------------------- credentials ---
            'credentialtype' => [
                'title'      => 'Credentials',
                'group'      => 'Credentials',
                'table'      => 'credential_types',
                'singular'   => 'credential',
                'lede'       => 'A named credential and the key that goes with it — for example "AI KEY" and the API key you paste in. The key is encrypted before it is written, so the column in the database holds ciphertext rather than the key itself.',
                'summary'    => 'Named credentials and their encrypted keys',
                // Nothing points at a credential, so nothing blocks deleting one.
                'referenced' => [],
                'fields'     => [
                    'name' => [
                        'label' => 'Name', 'type' => 'text',
                        'required' => true, 'unique' => true, 'max' => 100,
                        'hint'  => 'What this credential is for, e.g. AI KEY.',
                    ],
                    // Stored in credential_types.api_key as `enc:v1:<base64>`.
                    // Encrypted rather than hashed: unlike a password, the
                    // original value has to be handed back to whoever calls the
                    // provider, so it must be reversible.
                    'api_key' => [
                        'label' => 'API Key', 'type' => 'text', 'required' => true,
                        'max' => 255, 'secret' => true, 'encrypted' => true,
                        'hint'  => 'Stored encrypted. Shown in full here so you can copy it; masked in the list below.',
                    ],
                ],
            ],

            // ---------------------------------------------------------- user ---
            'user' => [
                'title'      => 'Create User',
                'group'      => 'Members',
                'table'      => 'users',
                'singular'   => 'user',
                'lede'       => 'Create an account and choose the usergroup it belongs to. The group is a foreign key, so it has to exist first.',
                'summary'    => 'Accounts and the usergroup they sign in under',
                // Nothing blocks deleting a user: their OTPs and tokens are
                // ON DELETE CASCADE, so they go with the account.
                'referenced' => [],
                'fields'     => [
                    'username' => [
                        'label' => 'Username', 'type' => 'text',
                        'required' => true, 'unique' => true, 'max' => 20, 'validate' => 'username',
                    ],
                    'email' => [
                        'label' => 'Email', 'type' => 'email',
                        'required' => true, 'unique' => true, 'max' => 254, 'validate' => 'email',
                    ],
                    // Stored in users.password_hash. Blank on edit keeps the current one.
                    'password' => [
                        'label' => 'Password', 'type' => 'password', 'column' => 'password_hash',
                        'required' => true, 'max' => 100, 'validate' => 'password', 'hash' => true,
                    ],
                    // users.usergroup_id -> usergroups.id (FK, RESTRICT)
                    'usergroup_id' => [
                        'label' => 'Usergroup', 'type' => 'fk', 'required' => true,
                        'ref'   => ['table' => 'usergroups', 'label' => 'title', 'order' => 'title ASC'],
                    ],
                    'status' => [
                        'label' => 'Status', 'type' => 'select', 'required' => true, 'default' => 'active',
                        'options' => ['pending' => 'Pending', 'active' => 'Active', 'suspended' => 'Suspended'],
                        'hint'  => 'An active account is marked as email-verified so it can sign in straight away.',
                    ],
                ],
            ],

            // ----------------------------------------------------- usergroup ---
            'usergroup' => [
                'title'      => 'Create Usergroup',
                'group'      => 'Members',
                'table'      => 'usergroups',
                'singular'   => 'usergroup',
                'lede'       => 'A group of members and the access it grants: the user interface, or the backend dashboard.',
                'summary'    => 'Groups and the access they grant',
                // users.usergroup_id -> usergroups.id (FK, RESTRICT)
                'referenced' => [['table' => 'users', 'column' => 'usergroup_id', 'label' => 'user']],
                'fields'     => [
                    'title' => [
                        'label' => 'Title', 'type' => 'text',
                        'required' => true, 'unique' => true, 'max' => 100,
                    ],
                    'slug' => [
                        'label' => 'Slug', 'type' => 'text',
                        'required' => true, 'unique' => true, 'max' => 50, 'validate' => 'slug',
                    ],
                    'access' => [
                        'label' => 'Access', 'type' => 'select', 'required' => true, 'default' => 'site',
                        'options' => [
                            'site'          => 'User interface (front end)',
                            'administrator' => 'Backend (admin dashboard)',
                        ],
                        'hint'  => 'Super User maps to backend access; Registered maps to the user interface.',
                    ],
                    'description' => [
                        'label' => 'Description', 'type' => 'text', 'max' => 255,
                    ],
                ],
            ],
        ];
    }
}
