<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\MenuItem;
use App\Support\Lookup;
use Illuminate\Database\Seeder;

/**
 * COPS polish seeds (idempotent): the currency lookup list (ISO 4217 codes) and professional sidebar icons for the menu entries added by Bundles 08-12.
 * Add or deactivate currencies in Administration > Lookups. rw / sw names are first-pass: have native speakers review them.
 */
class PolishSeeder extends Seeder
{
    private const ICONS = [
        'approvals' => 'IconChecklist', 'people' => 'IconUsersGroup', 'people.employees' => 'IconId', 'clients' => 'IconBuildingStore',
        'admin.rules' => 'IconScale', 'admin.approval_flows' => 'IconRoute', 'admin.dept_access' => 'IconSitemap',
        'projects' => 'IconLayoutKanban', 'time' => 'IconClock', 'admin.rate_cards' => 'IconCoin',
        'invoices' => 'IconFileInvoice', 'admin.tax_rates' => 'IconPercentage', 'admin.client_portal' => 'IconWorld',
    ];

    public function run(): void
    {
        $this->currencies();
        foreach (self::ICONS as $code => $icon) {
            MenuItem::where('code', $code)->update(['icon' => $icon]);
        }
    }

    private function currencies(): void
    {
        $langs = Language::pluck('id', 'code');
        $type = LookupType::where('code', 'currency')->first() ?? LookupType::create(['code' => 'currency', 'name' => 'Currency', 'is_system' => true]);
        $rows = [
            ['RWF', 'Rwandan franc', 'Franc rwandais', 'Ifaranga ry\'u Rwanda', 'Faranga ya Rwanda'],
            ['USD', 'US dollar', 'Dollar américain', 'Idolari y\'Amerika', 'Dola ya Marekani'],
            ['EUR', 'Euro', 'Euro', 'Ewuro', 'Euro'],
            ['GBP', 'Pound sterling', 'Livre sterling', 'Ipawundi y\'u Bwongereza', 'Pauni ya Uingereza'],
            ['KES', 'Kenyan shilling', 'Shilling kényan', 'Shilingi ya Kenya', 'Shilingi ya Kenya'],
            ['UGX', 'Ugandan shilling', 'Shilling ougandais', 'Shilingi ya Uganda', 'Shilingi ya Uganda'],
            ['TZS', 'Tanzanian shilling', 'Shilling tanzanien', 'Shilingi ya Tanzaniya', 'Shilingi ya Tanzania'],
            ['BIF', 'Burundian franc', 'Franc burundais', 'Ifaranga ry\'u Burundi', 'Faranga ya Burundi'],
            ['CDF', 'Congolese franc', 'Franc congolais', 'Ifaranga rya Kongo', 'Faranga ya Kongo'],
            ['ZAR', 'South African rand', 'Rand sud-africain', 'Randi y\'Afurika y\'Epfo', 'Randi ya Afrika Kusini'],
            ['NGN', 'Nigerian naira', 'Naira nigérian', 'Nayira ya Nijeriya', 'Naira ya Nigeria'],
            ['GHS', 'Ghanaian cedi', 'Cedi ghanéen', 'Sedi ya Gana', 'Cedi ya Ghana'],
            ['ETB', 'Ethiopian birr', 'Birr éthiopien', 'Biri y\'Etiyopiya', 'Birr ya Ethiopia'],
            ['XOF', 'West African CFA franc', 'Franc CFA (BCEAO)', 'Ifaranga CFA y\'Afurika y\'Iburengerazuba', 'Faranga ya CFA ya Afrika Magharibi'],
            ['XAF', 'Central African CFA franc', 'Franc CFA (BEAC)', 'Ifaranga CFA y\'Afurika yo Hagati', 'Faranga ya CFA ya Afrika ya Kati'],
            ['AED', 'UAE dirham', 'Dirham des Émirats', 'Dirihamu y\'Abarabu Bunze', 'Dirham ya UAE'],
            ['CAD', 'Canadian dollar', 'Dollar canadien', 'Idolari ya Kanada', 'Dola ya Kanada'],
            ['CHF', 'Swiss franc', 'Franc suisse', 'Ifaranga ry\'u Busuwisi', 'Faranga ya Uswisi'],
            ['CNY', 'Chinese yuan', 'Yuan chinois', 'Yuwani y\'u Bushinwa', 'Yuan ya China'],
            ['INR', 'Indian rupee', 'Roupie indienne', 'Irupiya y\'u Buhinde', 'Rupia ya India'],
        ];
        foreach ($rows as $i => [$code, $en, $fr, $rw, $sw]) {
            $v = LookupValue::updateOrCreate(['lookup_type_id' => $type->id, 'organization_id' => null, 'code' => $code],
                ['color' => 'gray', 'icon' => null, 'sort_order' => $i, 'is_system' => true, 'is_active' => true]);
            foreach (['en' => $en, 'fr' => $fr, 'rw' => $rw, 'sw' => $sw] as $lc => $label) {
                $v->translations()->updateOrCreate(['language_id' => $langs[$lc]], ['label' => $label]);
            }
        }
        Lookup::flush();
    }
}
