<?php

namespace Database\Seeders;

use App\Models\BotIntent;
use App\Models\DashboardWidget;
use App\Models\FileClassificationRule;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** Bundle 3 reference data. Idempotent. rw/sw text is first-pass and needs native review. */
class FilesSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);      // adds file.legal_hold
        $this->lookups();
        $this->menuAndWidgets();
        $this->bot();
        $this->roles();
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        $data = [
            'file_status' => ['File status', [
                ['active', 'green', null, 'Active', 'Actif', 'Ikora', 'Inatumika'],
                ['archived', 'gray', null, 'Archived', 'Archivé', 'Yabitswe mu bubiko', 'Imehifadhiwa'],
            ]],
            'file_classification' => ['File classification', [
                ['public', 'green', null, 'Public (everyone)', 'Public (tous)', 'Rusange (bose)', 'Umma (wote)'],
                ['internal', 'blue', null, 'Internal (staff)', 'Interne (personnel)', 'Imbere (abakozi)', 'Ndani (wafanyakazi)'],
                ['confidential', 'orange', null, 'Confidential (team leaders+)', 'Confidentiel (chefs d\'équipe et +)', 'Ibanga (abayobozi b\'amatsinda no hejuru)', 'Siri (viongozi wa timu na zaidi)'],
                ['restricted', 'red', null, 'Restricted (managers+)', 'Restreint (managers et +)', 'Byihariye cyane (abayobozi bakuru no hejuru)', 'Imezuiliwa (mameneja na zaidi)'],
            ]],
            'file_link_type' => ['File link type', [
                ['general', 'gray', null, 'General', 'Général', 'Rusange', 'Jumla'],
                ['case', 'blue', null, 'Case attachment', 'Pièce jointe de dossier', 'Icyometse ku kibazo', 'Kiambatisho cha kesi'],
                ['user_avatar', 'gray', null, 'Profile photo', 'Photo de profil', 'Ifoto y\'umwirondoro', 'Picha ya wasifu'],
                ['org_logo', 'gray', null, 'Organization logo', 'Logo de l\'organisation', 'Ikirango cy\'ikigo', 'Nembo ya shirika'],
            ]],
            'file_comment_kind' => ['File comment kind', [
                ['comment', 'gray', null, 'Comment', 'Commentaire', 'Igitekerezo', 'Maoni'],
                ['deletion_request', 'orange', null, 'Deletion request', 'Demande de suppression', 'Ubusabe bwo gusiba', 'Ombi la kufuta'],
                ['decision', 'blue', null, 'Decision', 'Décision', 'Icyemezo', 'Uamuzi'],
            ]],
            'deletion_status' => ['Deletion request status', [
                ['pending', 'orange', null, 'Pending', 'En attente', 'Itegereje', 'Inasubiri'],
                ['approved', 'green', null, 'Approved', 'Approuvée', 'Yemewe', 'Imekubaliwa'],
                ['rejected', 'red', null, 'Rejected', 'Rejetée', 'Yanzwe', 'Imekataliwa'],
                ['cancelled', 'gray', null, 'Cancelled', 'Annulée', 'Yahagaritswe', 'Imeghairiwa'],
            ]],
        ];

        foreach ($data as $typeCode => [$name, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $name, 'is_system' => true]);
            foreach ($rows as $i => [$code, $color, $icon, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => $icon, 'sort_order' => $i, 'is_system' => true, 'is_active' => true]);
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->firstOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
                }
            }
        }
        Lookup::flush();

        // Classification -> minimum role level (guest 10, staff 20, team leader 40, manager 60, admin 100).
        foreach (['public' => 0, 'internal' => 20, 'confidential' => 40, 'restricted' => 60] as $code => $min) {
            FileClassificationRule::firstOrCreate(['classification_id' => Lookup::id('file_classification', $code)], ['min_level' => $min]);
        }
    }

    private function menuAndWidgets(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;
        MenuItem::updateOrCreate(['code' => 'files'], ['parent_id' => null, 'label_key' => 'menu.files', 'icon' => 'IconFiles', 'route' => '/files',
            'permission_id' => $perm('file.view'), 'sort_order' => 20, 'is_active' => true]);

        foreach ([
            ['stat_files', 'stat_card', 'file.view', 3, 'IconFiles', 'teal'],
            ['stat_pending_deletions', 'stat_card', 'file.delete_approve', 3, 'IconTrashX', 'red'],
        ] as [$code, $type, $p, $w, $icon, $color]) {
            DashboardWidget::updateOrCreate(['code' => $code], ['name_key' => "widget.$code", 'widget_type_id' => Lookup::id('widget_type', $type),
                'permission_id' => $perm($p), 'default_width' => $w, 'icon' => $icon, 'color' => $color, 'is_active' => true]);
        }
    }

    private function bot(): void
    {
        $langs = Language::pluck('id', 'code');
        $intent = BotIntent::updateOrCreate(['code' => 'delete_file'], ['action_id' => Lookup::id('bot_action', 'none'), 'sort_order' => 8, 'is_active' => true]);
        if ($intent->keywords()->count() === 0) {
            foreach (['delete file', 'remove file', 'delete document', 'delete my file', 'supprimer fichier', 'supprimer document', 'supprimer un fichier', 'gusiba dosiye', 'gusiba inyandiko', 'futa faili', 'futa hati'] as $kw) {
                $intent->keywords()->create(['language_id' => $langs['en'], 'keyword' => $kw]);
            }
        }
        $resp = [
            'en' => 'Files are deleted through a formal request: open the file in Files, choose "Request deletion" and give the reason. The file owner or an administrator then approves or rejects it. Files under legal hold cannot be deleted.',
            'fr' => 'La suppression d\'un fichier passe par une demande formelle : ouvrez le fichier dans Fichiers, choisissez « Demander la suppression » et indiquez le motif. Le propriétaire du fichier ou un administrateur approuve ou rejette ensuite. Un fichier sous conservation légale ne peut pas être supprimé.',
            'rw' => 'Gusiba dosiye bikorwa binyuze mu busabe bwemewe: fungura dosiye mu « Dosiye », hitamo « Gusaba gusiba » ushyiremo impamvu. Nyirayo cyangwa umuyobozi aremeza cyangwa akanga. Dosiye iri mu kubungabungwa n\'amategeko ntishobora gusibwa.',
            'sw' => 'Faili hufutwa kupitia ombi rasmi: fungua faili katika Faili, chagua « Omba kufuta » na utoe sababu. Mmiliki wa faili au msimamizi kisha hukubali au hukataa. Faili zilizo chini ya zuio la kisheria haziwezi kufutwa.',
        ];
        foreach ($resp as $lc => $text) {
            $intent->responses()->firstOrCreate(['language_id' => $langs[$lc]], ['response' => $text]);
        }
    }

    private function roles(): void
    {
        $widgetIds = DashboardWidget::pluck('id', 'code');
        $perClass = ['guest' => ['stat_files'], 'staff' => ['stat_files'], 'team_leader' => ['stat_files'], 'manager' => ['stat_files'], 'admin' => ['stat_files', 'stat_pending_deletions']];

        foreach (Role::withoutGlobalScopes()->whereIn('code', array_keys($perClass))->get() as $role) {
            foreach ($perClass[$role->code] as $i => $code) {
                $role->widgets()->syncWithoutDetaching([$widgetIds[$code] => ['sort_order' => 40 + $i]]);
            }
            if ($role->code === 'admin') {
                $role->permissions()->syncWithoutDetaching([Permission::where('code', 'file.legal_hold')->value('id')]);
            }
        }
    }
}
