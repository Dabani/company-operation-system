<?php

namespace Database\Seeders;

use App\Models\BotIntent;
use App\Models\DashboardWidget;
use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Models\Permission;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/** Bundle 2 reference data. Idempotent: safe to run again. rw/sw text is first-pass and needs native review. */
class ServiceDeskSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PermissionSeeder::class);      // adds case.notify_new
        $this->lookups();
        $this->menuAndWidgets();
        $this->bot();
    }

    private function lookups(): void
    {
        $langs = Language::pluck('id', 'code');
        // [code, colour, icon, en, fr, rw, sw]
        $data = [
            'case_type' => ['Case type', [
                ['support_request', 'blue', 'IconHeadset', 'Support request', 'Demande d\'assistance', 'Ubusabe bw\'ubufasha', 'Ombi la msaada'],
                ['archive_request', 'teal', 'IconArchive', 'Archive request', 'Demande d\'archivage', 'Ubusabe bw\'ububiko', 'Ombi la kumbukumbu'],
                ['activity_report', 'green', 'IconTicket', 'Activity report', 'Rapport d\'activité', 'Raporo y\'ibikorwa', 'Ripoti ya shughuli'],
                ['incident', 'red', 'IconTicket', 'Incident report', 'Signalement d\'incident', 'Raporo y\'ikibazo cyabaye', 'Ripoti ya tukio'],
                ['asset_request', 'orange', 'IconTicket', 'Equipment / asset request', 'Demande de matériel', 'Ubusabe bw\'ibikoresho', 'Ombi la vifaa'],
            ]],
            'case_status' => ['Case status', [
                ['new', 'blue', null, 'New', 'Nouveau', 'Gishya', 'Mpya'],
                ['open', 'cyan', null, 'Open', 'Ouvert', 'Gifunguye', 'Wazi'],
                ['in_progress', 'orange', null, 'In progress', 'En cours', 'Kirimo gukorwa', 'Inaendelea'],
                ['pending_info', 'yellow', null, 'Waiting for information', 'En attente d\'informations', 'Hategerejwe amakuru', 'Inasubiri taarifa'],
                ['resolved', 'teal', null, 'Resolved', 'Résolu', 'Cyakemutse', 'Imetatuliwa'],
                ['closed', 'gray', null, 'Closed', 'Clôturé', 'Gifunze', 'Imefungwa'],
                ['cancelled', 'red', null, 'Cancelled', 'Annulé', 'Cyahagaritswe', 'Imeghairiwa'],
            ]],
            'case_priority' => ['Case priority', [
                ['low', 'gray', null, 'Low', 'Faible', 'Gito', 'Chini'],
                ['normal', 'blue', null, 'Normal', 'Normale', 'Isanzwe', 'Kawaida'],
                ['high', 'orange', null, 'High', 'Élevée', 'Hejuru', 'Juu'],
                ['urgent', 'red', null, 'Urgent', 'Urgente', 'Byihutirwa', 'Dharura'],
            ]],
            'case_event_type' => ['Case event type', [
                ['created', 'blue', null, 'Created', 'Créé', 'Cyakozwe', 'Imeundwa'],
                ['status_changed', 'orange', null, 'Status changed', 'Statut modifié', 'Imiterere yahindutse', 'Hali imebadilika'],
                ['priority_changed', 'orange', null, 'Priority changed', 'Priorité modifiée', 'Ibyihutirwa byahindutse', 'Kipaumbele kimebadilika'],
                ['assigned', 'teal', null, 'Assigned', 'Assigné', 'Cyahawe', 'Imekabidhiwa'],
                ['due_changed', 'yellow', null, 'Due date changed', 'Échéance modifiée', 'Itariki ntarengwa yahindutse', 'Tarehe ya mwisho imebadilika'],
            ]],
            'notification_channel' => ['Notification channel', [
                ['in_app', 'blue', null, 'In-app', 'Dans l\'application', 'Mu rubuga', 'Ndani ya programu'],
                ['email', 'teal', null, 'Email', 'E-mail', 'Imeri', 'Barua pepe'],
                ['sms', 'green', null, 'SMS', 'SMS', 'SMS', 'SMS'],
            ]],
            'delivery_status' => ['Delivery status', [
                ['pending', 'yellow', null, 'Pending', 'En attente', 'Itegereje', 'Inasubiri'],
                ['sent', 'green', null, 'Sent', 'Envoyé', 'Yoherejwe', 'Imetumwa'],
                ['failed', 'red', null, 'Failed', 'Échec', 'Ntiyagenze', 'Imeshindwa'],
            ]],
            'chat_status' => ['Chat status', [
                ['bot', 'blue', null, 'With assistant', 'Avec l\'assistant', 'Ku mufasha wa mudasobwa', 'Na msaidizi'],
                ['waiting_agent', 'orange', null, 'Waiting for agent', 'En attente d\'un agent', 'Hategerejwe umukozi', 'Inasubiri wakala'],
                ['with_agent', 'green', null, 'With agent', 'Avec un agent', 'Ku mukozi', 'Na wakala'],
                ['closed', 'gray', null, 'Closed', 'Clôturé', 'Gifunze', 'Imefungwa'],
            ]],
            'chat_sender' => ['Chat sender', [
                ['user', 'gray', null, 'User', 'Utilisateur', 'Umukoresha', 'Mtumiaji'],
                ['agent', 'green', null, 'Agent', 'Agent', 'Umukozi', 'Wakala'],
                ['bot', 'blue', null, 'Assistant', 'Assistant', 'Umufasha', 'Msaidizi'],
            ]],
            'bot_action' => ['Bot action', [
                ['none', 'gray', null, 'None', 'Aucune', 'Nta na kimwe', 'Hakuna'],
                ['escalate', 'orange', null, 'Hand over to agent', 'Passer à un agent', 'Guha umukozi', 'Peleka kwa wakala'],
                ['suggest_case', 'green', null, 'Suggest creating a case', 'Suggérer de créer un dossier', 'Gutanga igitekerezo cyo gukora ikibazo', 'Pendekeza kuunda kesi'],
            ]],
        ];

        foreach ($data as $typeCode => [$name, $rows]) {
            $type = LookupType::updateOrCreate(['code' => $typeCode], ['name' => $name, 'is_system' => true]);
            foreach ($rows as $i => [$code, $color, $icon, $en, $fr, $rw, $sw]) {
                $v = LookupValue::updateOrCreate(
                    ['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                    ['color' => $color, 'icon' => $icon, 'sort_order' => $i, 'is_system' => true, 'is_active' => true]
                );
                foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                    $v->translations()->firstOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);   // keeps admin edits
                }
            }
        }
        Lookup::flush();
    }

    private function menuAndWidgets(): void
    {
        $perm = fn (?string $c) => $c ? Permission::where('code', $c)->value('id') : null;
        foreach ([
            ['desk', null, 'IconHeadset', null, null, 10],
            ['desk.cases', 'desk', 'IconTicket', '/cases', 'case.view_own', 1],
            ['desk.chats', 'desk', 'IconMessages', '/support/chats', 'chat.moderate', 2],
        ] as [$code, $parent, $icon, $route, $p, $order]) {
            MenuItem::updateOrCreate(['code' => $code], [
                'parent_id' => $parent ? MenuItem::where('code', $parent)->value('id') : null,
                'label_key' => "menu.$code", 'icon' => $icon, 'route' => $route, 'permission_id' => $perm($p), 'sort_order' => $order, 'is_active' => true,
            ]);
        }

        foreach ([
            ['stat_my_cases', 'stat_card', 'case.view_own', 3, 'IconTicket', 'blue'],
            ['stat_open_cases', 'stat_card', 'case.view_all', 3, 'IconHeadset', 'orange'],
            ['chart_cases_by_status', 'donut_chart', 'case.view_all', 6, null, null],
        ] as [$code, $type, $p, $w, $icon, $color]) {
            DashboardWidget::updateOrCreate(['code' => $code], [
                'name_key' => "widget.$code", 'widget_type_id' => Lookup::id('widget_type', $type),
                'permission_id' => $perm($p), 'default_width' => $w, 'icon' => $icon, 'color' => $color, 'is_active' => true,
            ]);
        }
    }

    private function bot(): void
    {
        $langs = Language::pluck('id', 'code');
        $act = fn ($c) => Lookup::id('bot_action', $c);

        // code => [action, order, keywords (all languages, lower case), responses [en, fr, rw, sw]]
        $intents = [
            'greeting' => ['none', 1,
                ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'bonjour', 'salut', 'bonsoir', 'muraho', 'mwiriwe', 'mwaramutse', 'habari', 'hujambo', 'jambo'],
                ['Hello! I am the support assistant. I can help with passwords, incidents, archive and equipment requests, or connect you to an agent. What do you need?',
                 'Bonjour ! Je suis l\'assistant d\'aide. Je peux vous aider pour les mots de passe, incidents, demandes d\'archivage ou de matériel, ou vous mettre en relation avec un agent. De quoi avez-vous besoin ?',
                 'Muraho! Ndi umufasha wa serivisi z\'ubufasha. Nshobora kugufasha ku ijambobanga, ibyabaye, gusaba ububiko cyangwa ibikoresho, cyangwa kukugeza ku mukozi. Ukeneye iki?',
                 'Habari! Mimi ni msaidizi wa msaada. Naweza kusaidia kuhusu nenosiri, matukio, maombi ya kumbukumbu au vifaa, au kukuunganisha na wakala. Unahitaji nini?']],
            'thanks' => ['none', 2,
                ['thanks', 'thank you', 'merci', 'murakoze', 'asante', 'shukrani'],
                ['You are welcome! Is there anything else I can help with?', 'Avec plaisir ! Puis-je vous aider autrement ?', 'Nta kibazo! Hari ikindi nagufasha?', 'Karibu! Kuna jambo lingine ninaloweza kusaidia?']],
            'password' => ['suggest_case', 3,
                ['password', 'forgot', 'reset', 'locked', 'login', 'sign in', 'mot de passe', 'mdp', 'connexion', 'ijambobanga', 'kwinjira', 'nenosiri', 'kuingia'],
                ['To reset your password use "Forgot your password?" on the sign-in page; a reset link is sent to your email. If you are locked out or receive no email, create a support case and the team will help.',
                 'Pour réinitialiser votre mot de passe, utilisez « Mot de passe oublié ? » sur la page de connexion ; un lien est envoyé par e-mail. Si vous êtes bloqué ou ne recevez rien, créez un dossier d\'assistance.',
                 'Kugira ngo uhindure ijambobanga, kanda kuri « Wibagiwe ijambobanga? » ku rupapuro rwo kwinjira; link yoherezwa kuri imeri yawe. Niba udashobora kwinjira, kora ikibazo cy\'ubufasha.',
                 'Ili kuweka upya nenosiri tumia « Umesahau nenosiri? » kwenye ukurasa wa kuingia; kiungo hutumwa kwa barua pepe yako. Ikiwa umefungiwa nje, unda kesi ya msaada.']],
            'incident' => ['suggest_case', 4,
                ['incident', 'lost', 'stolen', 'theft', 'virus', 'malware', 'breach', 'hacked', 'phishing', 'suspicious', 'outage', 'perdu', 'vol', 'volé', 'piratage', 'hameçonnage', 'panne', 'byabuze', 'yibwe', 'ubujura', 'virusi', 'kwibwa', 'wizi', 'kupotea', 'hitilafu'],
                ['Lost or stolen equipment, security incidents and outages should be reported as soon as possible. Please create an Incident report case now; if it is urgent I can connect you to an agent.',
                 'Tout équipement perdu ou volé, incident de sécurité ou panne doit être signalé au plus vite. Créez dès maintenant un dossier « Signalement d\'incident » ; si c\'est urgent, je peux vous passer un agent.',
                 'Ibikoresho byabuze cyangwa byibwe, ibibazo by\'umutekano n\'ibyangiritse bigomba kumenyeshwa vuba. Kora ikibazo cy\'ubwoko « Raporo y\'ikibazo cyabaye » ubu; niba byihutirwa nagushyira ku mukozi.',
                 'Vifaa vilivyopotea au kuibwa, matukio ya usalama na hitilafu vinapaswa kuripotiwa mapema. Tafadhali unda kesi ya « Ripoti ya tukio » sasa; ikiwa ni dharura naweza kukuunganisha na wakala.']],
            'archive' => ['suggest_case', 5,
                ['archive', 'archiving', 'records', 'document', 'retrieve', 'retrieval', 'storage', 'archives', 'dossier', 'ububiko', 'inyandiko', 'hifadhi', 'kumbukumbu'],
                ['To request retrieval, transfer or disposal of records, create an Archive request case with the document reference and the reason.',
                 'Pour demander la récupération, le transfert ou l\'élimination de documents, créez un dossier « Demande d\'archivage » avec la référence du document et le motif.',
                 'Gusaba gusubirana, kwimura cyangwa gusiba inyandiko, kora ikibazo « Ubusabe bw\'ububiko » ushyiremo nimero y\'inyandiko n\'impamvu.',
                 'Kuomba kurejesha, kuhamisha au kuharibu rekodi, unda kesi ya « Ombi la kumbukumbu » ukiweka rejea ya hati na sababu.']],
            'equipment' => ['suggest_case', 6,
                ['laptop', 'computer', 'equipment', 'asset', 'printer', 'device', 'software', 'licence', 'license', 'ordinateur', 'matériel', 'imprimante', 'ibikoresho', 'mudasobwa', 'vifaa', 'kompyuta'],
                ['For new equipment, repairs, software, or moving equipment off the premises, create an Equipment / asset request case.',
                 'Pour du nouveau matériel, une réparation, un logiciel ou la sortie de matériel des locaux, créez un dossier « Demande de matériel ».',
                 'Ku bikoresho bishya, gusana, porogaramu cyangwa gusohora ibikoresho, kora ikibazo « Ubusabe bw\'ibikoresho ».',
                 'Kwa vifaa vipya, ukarabati, programu au kutoa vifaa nje ya majengo, unda kesi ya « Ombi la vifaa ».']],
            'human_agent' => ['escalate', 7,
                ['agent', 'human', 'person', 'representative', 'someone', 'operator', 'humain', 'conseiller', 'quelqu\'un', 'umukozi', 'umuntu', 'mtu', 'wakala', 'binadamu'],
                ['Connecting you to a support agent. Please wait a moment.', 'Je vous mets en relation avec un agent. Veuillez patienter un instant.', 'Ngiye kukugeza ku mukozi w\'ubufasha. Tegereza gato.', 'Ninakuunganisha na wakala wa msaada. Tafadhali subiri kidogo.']],
            'fallback' => ['suggest_case', 99,
                [],
                ['I am not sure I understood. You can rephrase, talk to an agent, or create a case so the team can follow up.',
                 'Je ne suis pas sûr d\'avoir compris. Reformulez, parlez à un agent, ou créez un dossier pour un suivi.',
                 'Ntabwo nsobanukiwe neza. Ongera ubivuge ukundi, vugana n\'umukozi, cyangwa kora ikibazo kugira ngo itsinda rikurikirane.',
                 'Sina uhakika nimeelewa. Unaweza kueleza upya, kuzungumza na wakala, au kuunda kesi ili timu ifuatilie.']],
        ];

        foreach ($intents as $code => [$action, $order, $keywords, $responses]) {
            $intent = BotIntent::updateOrCreate(['code' => $code], ['action_id' => $act($action), 'sort_order' => $order, 'is_active' => true]);

            // Keyword lists are multilingual by design (stored once, matched for every user language). Admin edits are kept on re-seed.
            if ($intent->keywords()->count() === 0) {
                foreach ($keywords as $kw) {
                    $intent->keywords()->create(['language_id' => $langs['en'], 'keyword' => $kw]);
                }
            }
            foreach (array_combine(['en', 'fr', 'rw', 'sw'], $responses) as $lc => $text) {
                $intent->responses()->firstOrCreate(['language_id' => $langs[$lc]], ['response' => $text]);
            }
        }
    }
}
