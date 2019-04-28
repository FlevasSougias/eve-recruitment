<?php

namespace App\Connectors;

use GuzzleHttp\Client;
use Swagger\Client\Eve\ApiException;
use App\Models\Group;
use App\Models\Type;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Seat\Eseye\Eseye;
use Swagger\Client\Eve\Api\AssetsApi;
use Swagger\Client\Eve\Api\CharacterApi;
use Swagger\Client\Eve\Api\ClonesApi;
use Swagger\Client\Eve\Api\ContactsApi;
use Swagger\Client\Eve\Api\ContractsApi;
use Swagger\Client\Eve\Api\LocationApi;
use Swagger\Client\Eve\Api\MailApi;
use Swagger\Client\Eve\Api\MarketApi;
use Swagger\Client\Eve\Api\SkillsApi;
use Swagger\Client\Eve\Api\UniverseApi;
use Swagger\Client\Eve\Api\WalletApi;
use Swagger\Client\Eve\Configuration;
use Symfony\Component\Yaml\Yaml;

/**
 * Handles the connection between the recruitment site and core
 *
 * Class EsiModel
 * @package App\Models
 */
class EsiConnection
{

    /**
     * Store the configuration instance
     *
     * @var Configuration $config
     */
    private $config;

    /**
     * Character ID the instance is created for
     *
     * @var int $char_id
     */
    private $char_id;

    /**
     * Eseye instance
     *
     * @var Eseye $eseye
     */
    private $eseye;

    /**
     * Guzzle client
     *
     * @var Client
     */
    private $client;

    private static $stationContentLocationFlags = [
        "AssetSafety",
        "Deliveries",
        "Hangar",
        "HangarAll"
    ];

    /**
     * EsiModel constructor
     *
     * @param int $char_id Char ID to create the instance for
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     */
    public function __construct($char_id)
    {
        $config = new Configuration();
        $config->setHost(env('CORE_URL') . '/api/app/v1/esi');
        $config->setAccessToken(base64_encode(env('CORE_APP_ID') . ':' . env('CORE_APP_SECRET')));

        $eseye_config = \Seat\Eseye\Configuration::getInstance();
        $eseye_config->logfile_location = storage_path() . '/logs';
        $eseye_config->file_cache_location = storage_path() . '/framework/cache';

        $this->eseye = new Eseye();
        $this->config = $config;
        $this->char_id = $char_id;

        $this->client = new Client(['timeout' => 0]);
    }

    /**
     * Get a user's wallet balance
     *
     * @return string
     */
    public function getWalletBalance()
    {
        $model = new WalletApi($this->client, $this->config);

        try {
            $balance = number_format($model->getCharactersCharacterIdWallet($this->char_id, $this->char_id));
        } catch(ApiException $e) {
            return null;
        }

        return $balance;
    }

    /**
     * Get a user's corp history
     *
     * @return \Seat\Eseye\Containers\EsiResponse
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getCorpHistory()
    {
        $history = $this->eseye->invoke('get', '/characters/{character_id}/corporationhistory/', [
            'character_id' => $this->char_id
        ]);

        $data = json_decode($history->raw);

        // Get corporation names and alliance information
        foreach ($data as $d)
        {
            $corp_info = $this->eseye->invoke('get', '/corporations/{corporation_id}/', [
                'corporation_id' => $d->corporation_id
            ]);
            $d->corporation_name = $corp_info->name;

            $alliance_id = (isset($corp_info->alliance_id)) ? $corp_info->alliance_id : null;
            $d->alliance_id = $alliance_id;
            $d->alliance_name = $this->getAllianceName($alliance_id);
        }

        return $data;
    }

    /**
     * Get a character's information
     *
     * @return array
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getCharacterInfo()
    {
        try {
            $locationModel = new LocationApi($this->client, $this->config);
            $location = $locationModel->getCharactersCharacterIdLocation($this->char_id, $this->char_id);

            $skillsModel = new SkillsApi($this->client, $this->config);
            $attributes = $skillsModel->getCharactersCharacterIdAttributes($this->char_id, $this->char_id);

            if ($location->getStructureId() == null && $location->getStationId() == null)
                $location->structure_name = "In Space (" . $this->getSystemName($location->getSolarSystemId()) . ")";
            else if ($location->getStructureId() != null)
                $location->structure_name = $this->getStructureName($location->getStructureId());
            else
                $location->structure_name = $this->getStationName($location->getStationId());

            $ship = $locationModel->getCharactersCharacterIdShip($this->char_id, $this->char_id);
        } catch(\Exception $e) {
            $location = $ship = $attributes = null;
        }
        $public_data = $this->eseye->invoke('get', '/characters/{character_id}/', [
            "character_id" => $this->char_id
        ]);
        return [
            'location' => $location,
            'birthday' => explode('T', $public_data->birthday)[0],
            'gender' => ucfirst($public_data->gender),
            'ancestry' => $this->getAncestry($public_data->ancestry_id),
            'bloodline' => $this->getBloodline($public_data->bloodline_id),
            'race' => $this->getRace($public_data->race_id),
            'current_ship' => ($ship != null) ? $ship->getShipName() . " (" . $this->getTypeName($ship->getShipTypeId()) . ")" : null,
            'security_status' => round($public_data->security_status, 4),
            'region' => ($location != null) ? $this->getRegionName($location->getSolarSystemId()) : null,
            'attributes' => $attributes
        ];
    }

    /**
     * Get a character's clone information
     *
     * @return array
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getCloneInfo()
    {
        $model = new ClonesApi($this->client, $this->config);

        $implants = $model->getCharactersCharacterIdImplants($this->char_id, $this->char_id);
        foreach ($implants as $idx => $implant)
            $implants[$idx] = $this->getTypeName($implant);

        $clones = $model->getCharactersCharacterIdClones($this->char_id, $this->char_id);
        $home = $clones->getHomeLocation();
        $home->location_name = $this->getLocationBasedOnStationType($home->getLocationType(), $home->getLocationId());

        foreach ($clones->getJumpClones() as $clone)
            $clone->location_name = $this->getLocationBasedOnStationType($clone->getLocationType(), $clone->getLocationId());

        return ['implants' => $implants, 'clones' => $clones];
    }

    /**
     * Determine if a character can fly a fit
     *
     * @param $item_id
     * @return bool
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function characterCanUseItem($item_id)
    {
        // Pulled from SDE
        $requiredSkillDogmaAttributes = [
            182,
            183,
            184,
            1285,
            1289,
            1290
        ];
        $requiredSkillDogmaAttributesLevels = [
            277,
            278,
            279,
            1286,
            1287,
            1288
        ];
        $requiredSkills = [];

        $attributes = $this->eseye->invoke('get', '/universe/types/{type_id}/', [
            'type_id' => $item_id
        ])->dogma_attributes;

        foreach ($attributes as $attribute)
        {
            if (in_array($attribute->attribute_id, $requiredSkillDogmaAttributes))
            {
                $idx = array_search($attribute->attribute_id, $requiredSkillDogmaAttributes);

                if (!array_key_exists($idx, $requiredSkills))
                    $requiredSkills[$idx] = [];

                $requiredSkills[$idx]['skill'] = $this->getTypeName(floor($attribute->value));
            }
            else if (in_array($attribute->attribute_id, $requiredSkillDogmaAttributesLevels))
            {
                $idx = array_search($attribute->attribute_id, $requiredSkillDogmaAttributesLevels);

                if (!array_key_exists($idx, $requiredSkills))
                    $requiredSkills[$idx] = [];

                $requiredSkills[$idx]['level'] = (int) number_format($attribute->value);
            }
        }

        foreach ($requiredSkills as $requirement)
        {
            if (!$this->userHasSkillLevel($requirement['skill'], $requirement['level']))
                return false;
        }

        return true;
    }

    /**
     * Check if a user meets skillplan requirements
     *
     * @param $skillplan
     * @return array
     * @throws ApiException
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function checkSkillplan($skillplan)
    {
        $missing = [];

        foreach ($skillplan as $skill => $level)
        {
            if (!$this->userHasSkillLevel($skill, $level))
                $missing[] = "$skill $level";
        }

        return $missing;
    }

    /**
     * Given a skill name and level, check if the user has it
     *
     * @param $skill
     * @param $level
     * @return bool
     * @throws ApiException
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    private function userHasSkillLevel($skill, $level)
    {
        static $skills = null;

        if (!$skills)
            $skills = $this->getSkills();

        foreach ($skills as $category)
        {
            foreach ($category as $skillName => $attributes)
            {
                if ($skillName == $skill && $attributes['level'] >= $level)
                    return true;
            }
        }

        return false;
    }

    /**
     * Get a user's mail
     *
     * @return \Swagger\Client\Eve\Model\GetCharactersCharacterIdMail200Ok[]
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getMail()
    {
        $mailCacheKey = "mail_{$this->char_id}";
        $mailBodyCacheKey = "mail_body_";
        $model = new MailApi($this->client, $this->config);
        $ids = [];

        if (Cache::has($mailCacheKey))
            $mail = Cache::get($mailCacheKey);
        else
        {
            $mail = $model->getCharactersCharacterIdMailWithHttpInfo($this->char_id, $this->char_id);
            Cache::add($mailCacheKey, $mail[0], $this->getCacheExpirationTime($mail));
            $mail = $mail[0];
        }

        foreach ($mail as $m)
        {
            if (Cache::has($mailBodyCacheKey . $m->getMailId()))
                $m->contents = Cache::get($mailBodyCacheKey . $m->getMailId());
            else
            {
                $m->contents = $model->getCharactersCharacterIdMailMailId($this->char_id, $m->getMailId(), $this->char_id)->getBody();
                Cache::add($mailBodyCacheKey . $m->getMailId(), $m->contents, env('CACHE_TIME', 3264));
            }

            $m->sender = $this->getCharacterName($m->getFrom());
            $m->recipients = [];

            foreach ($m->getRecipients() as $recipient)
            {
                switch ($recipient->getRecipientType())
                {
                    case 'character':
                        $m->recipients[] = [
                            'type' => 'character',
                            'id' => $recipient->getRecipientId(),
                            'name' => null
                        ];
                        break;

                    case 'corporation':
                        $m->recipients[] = [
                            'type' => 'corporation',
                            'id' => $recipient->getRecipientId(),
                            'name' => null
                        ];
                        break;

                    case 'alliance':
                        $m->recipients[] = [
                            'type' => 'alliance',
                            'id' => $recipient->getRecipientId(),
                            'name' => null
                        ];
                        break;

                    case 'mailing_list':
                        $m->recipients[] = [
                            'type' => 'mailing list',
                            'name' => $this->getMailingListName($recipient->getRecipientId()),
                            'id' => null
                        ];
                        break;

                    default:
                        break;
                }

                if (in_array($recipient->getRecipientType(), ['character', 'corporation', 'alliance']) && !in_array($recipient->getRecipientId(), $ids))
                    $ids[] = $recipient->getRecipientId();
            }
        }

        $res = $this->eseye->setBody($ids)->invoke('post', '/universe/names/');

        if (!$res)
            return null;

        $data = json_decode($res->raw, true);
        $new_ids = [];

        foreach ($data as $d)
            $new_ids[$d['id']] = $d['name'];

        foreach ($mail as $m)
        {
            foreach ($m->recipients as &$recipient)
            {
                if ($recipient['name'] == null)
                        $recipient['name'] = array_key_exists($recipient['id'], $new_ids) ? $new_ids[$recipient['id']] : 'Unknown recipient';
            }
        }

        return $mail;
    }

    /**
     * Get a character's skills
     *
     * @return array|mixed
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getSkills()
    {
        $cache_key = "skills_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new SkillsApi($this->client, $this->config);
        $skills = $model->getCharactersCharacterIdSkillsWithHttpInfo($this->char_id, $this->char_id);
        $unprocessed_skills = $skills[0]->getSkills();
        $out = [];

        foreach ($unprocessed_skills as $skill)
        {
            $skill_name = $this->getTypeName($skill->getSkillId());
            $skill_category = $this->getGroupName($skill->getSkillId());

            if (!array_key_exists($skill_category, $out))
                $out[$skill_category] = [];

            $out[$skill_category][$skill_name] = [
                'skillpoints' => $skill->getSkillpointsInSkill(),
                'level' => $skill->getActiveSkillLevel()
            ];
        }

        foreach ($out as &$category)
        {
            uksort($category, function ($a, $b) use($category) {
                $skill_a = $category[$a];
                $skill_b = $category[$b];

                if ($skill_a['level'] == $skill_b['level'])
                    return strcmp($a, $b);

                return $skill_a['level'] < $skill_b['level'] ? 1 : -1;
            });
        }

        ksort($out);

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($skills));
        return $out;
    }

    /**
     * Get a character's skillqueue
     *
     * @return array|mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getSkillQueue()
    {
        $cache_key = "skill_queue_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new SkillsApi($this->client, $this->config);
        $queue = $model->getCharactersCharacterIdSkillqueueWithHttpInfo($this->char_id, $this->char_id);
        $out = [];

        foreach ($queue[0] as $skill)
        {
            $out[] = [
                'skill' => $this->getTypeName($skill->getSkillId()),
                'end_level' => $skill->getFinishedLevel()
            ];
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($queue));

        return $out;
    }

    /**
     * Get a user's assets
     *
     * @return array
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getAssets()
    {
        $cache_key = "assets_{$this->char_id}";
        $names_to_fetch = [];

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new AssetsApi($this->client, $this->config);
        $assets = $model->getCharactersCharacterIdAssetsWithHttpInfo($this->char_id, $this->char_id);
        $out = [];

        for ($i = 2; $i <= $assets[2]['X-Pages'][0]; $i++)
            $assets[0] = array_merge($assets[0], $model->getCharactersCharacterIdAssets($this->char_id, $this->char_id, null, $i));

        foreach ($assets[0] as $asset)
        {
            if (in_array($asset->getLocationFlag(), self::$stationContentLocationFlags))
            {
                $location = $this->getLocationName($asset->getLocationId());
                $names_to_fetch[] = $asset->getItemId();

                if (!array_key_exists($location, $out))
                    $out[$location] = [
                        'id' => $asset->getLocationId(),
                        'items' => []
                    ];

                $out[$location]['items'][] = $this->constructAssetTreeForItem($asset, $assets[0]);

                $location_price = 0;
                foreach ($out[$location]['items'] as $item)
                    $location_price += (int) filter_var($item['value'], FILTER_SANITIZE_NUMBER_INT);

                $out[$location]['value'] = number_format($location_price);
            }
        }

        $names = $model->postCharactersCharacterIdAssetsNames($this->char_id, json_encode($names_to_fetch), $this->char_id);

        foreach ($out as &$items)
            foreach ($items['items'] as &$item)
                $item['item_name'] = $this->getAssetNameFromArray($names, $item['id']);

        uasort($out, "self::cmp_assets");

        foreach ($out as $location => &$location_details)
        {
            uasort($location_details['items'], "self::cmp_assets");
            foreach ($location_details['items'] as &$location_items)
                if (count($location_items['items']) > 0)
                    uasort($location_items['items'], "self::cmp_assets");
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($assets));

        return $out;
    }

    private static function cmp_assets ($a, $b) {
        $v1 = (int) filter_var($a['value'], FILTER_SANITIZE_NUMBER_INT);
        $v2 = (int) filter_var($b['value'], FILTER_SANITIZE_NUMBER_INT);

        if ($v1 == $v2)
            return 0;

        return ($v1 > $v2) ? -1 : 1;
    }

    /**
     * Get an asset name from a nested array
     *
     * @param $items
     * @param $item_id
     * @return string
     */
    private function getAssetNameFromArray($items, $item_id)
    {
        foreach ($items as $key => $item)
            if ($item->getItemId() == $item_id)
            {
                $ret = $item->getName();
                unset($items[$key]);
                return $ret;
            }

        return 'None';
    }

    /**
     * Get the asset tree for an item that can contain other items, like a container or ship
     *
     * @param $item
     * @param $assets
     * @return array
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    private function constructAssetTreeForItem($item, &$assets)
    {
        $tree = [];
        $tree['name'] = $this->getTypeName($item->getTypeId());
        $tree['location'] = $this->getLocationName($item->getLocationId());
        $tree['quantity'] = number_format($item->getQuantity());
        $tree['id'] = $item->getItemId();
        $tree['type_id'] = $item->getTypeId();
        $tree['price'] = number_format((int) $this->getMarketPrice($item->getTypeId()) * $item->getQuantity(), 0);
        $tree['value'] = $this->getMarketPrice($item->getTypeId()) * $item->getQuantity();
        $tree['items'] = [];

        foreach ($assets as $idx => $asset)
        {

            // TODO: Nested container items
            if ($asset->getLocationId() == $item->getItemId())
            {
                $price = (int) $this->getMarketPrice($asset->getTypeId()) * $asset->getQuantity();
                $tree['items'][] = [
                    'name' => $this->getTypeName($asset->getTypeId()),
                    'quantity' => number_format($asset->getQuantity()),
                    'type_id' => $asset->getTypeId(),
                    'value' => number_format($price),
                    'items' => []
                ];

                $tree['value'] += $price;
                unset($assets[$idx]);
            }
        }

        $tree['value'] = number_format($tree['value']);

        return $tree;
    }

    /**
     * Get the market price for an item
     *
     * @param $type_id
     * @return int|string
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    private function getMarketPrice($type_id)
    {
        static $lookup_table = null;
        $cache_key = "market_prices";

        if ($lookup_table == null)
        {
            if (Cache::has($cache_key))
                $market = Cache::get($cache_key);
            else
            {
                $res = $this->eseye->invoke('get', '/markets/prices/');
                $market = json_decode($res->raw);
                Cache::add($cache_key, $market, 60);
            }

            $lookup_table = [];
            foreach ($market as $entry)
                $lookup_table[$entry->type_id] = $entry->adjusted_price;
        }

        return (array_key_exists($type_id, $lookup_table) ? $lookup_table[$type_id] : 0);
    }

    /**
     * Get a user's wallet transactions
     *
     * @return mixed
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getTransactions()
    {
        $cache_key = "wallet_transactions_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new WalletApi($this->client, $this->config);
        $res = $model->getCharactersCharacterIdWalletTransactionsWithHttpInfo($this->char_id, $this->char_id);
        $out = [];

        foreach ($res[0] as $transaction)
        {
            $out[] = [
                'date' => $transaction->getDate()->format('Y-m-d H:i:s'),
                'client' => $this->getCharacterName($transaction->getClientId()),
                'item' => $this->getTypeName($transaction->getTypeId()),
                'quantity' => $transaction->getQuantity(),
                'change' => number_format((int) $transaction->getQuantity() * (int) $transaction->getUnitPrice()),
                'buy' => $transaction->getIsBuy()
            ];
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($res));
        return $out;
    }

    /**
     * Get a character's market orders
     *
     * @return array|mixed
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getMarketOrders()
    {
        $cache_key = "market_orders_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new MarketApi($this->client, $this->config);
        $res = $model->getCharactersCharacterIdOrdersWithHttpInfo($this->char_id, $this->char_id);
        $out = [];

        foreach ($res[0] as $order)
        {
            $out[] = [
                'date' => $order->getIssued()->format('Y-m-d H:i:s'),
                'time_remaining' => $order->getDuration() - floor((time() - $order->getIssued()->format('U')) / 86400),
                'location' => $this->getLocationName($order->getLocationId()),
                'item' => $this->getTypeName($order->getTypeId()),
                'price' => number_format($order->getPrice(), 2),
                'buy' => $order->getIsBuyOrder(),
                'quantity_total' => $order->getVolumeTotal(),
                'quantity_remain' => $order->getVolumeRemain()
            ];
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($res));
        return $out;
    }

    /**'
     * Get user notifications
     *
     * @return array|mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getNotifications()
    {
        $cache_key = "notifications_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new CharacterApi($this->client, $this->config);
        $notifications = $model->getCharactersCharacterIdNotificationsWithHttpInfo($this->char_id, $this->char_id);
        $out = [];

        foreach ($notifications[0] as $notification)
        {
            $name = null;
            switch($notification->getSenderType())
            {
                case 'character':
                    $name = $this->getCharacterName($notification->getSenderId());
                    break;
                case 'corporation':
                    $name = $this->getCorporationName($notification->getSenderId());
                    break;
                case 'alliance':
                    $name = $this->getAllianceName($notification->getSenderId());
                    break;
                default:
                    $name = 'Other';
                    break;
            }

            $out[] = [
                'sender' => $name,
                'type' => $notification->getType(),
                'variables' => Yaml::dump(Yaml::parse($notification->getText())),
                'timestamp' => $notification->getTimestamp()->format('Y-m-d H:i')
            ];
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($notifications));

        return $out;
    }

    /**
     * Get a character's contracts
     *
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getContracts()
    {
        $cache_key = "contracts_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new ContractsApi($this->client, $this->config);
        $contracts = $model->getCharactersCharacterIdContractsWithHttpInfo($this->char_id, $this->char_id);
        $out = [];

        foreach ($contracts[0] as $contract)
        {
            $model_items = $model->getCharactersCharacterIdContractsContractIdItems($this->char_id, $contract->getContractId(), $this->char_id);
            $items = [];

            foreach ($model_items as $item)
            {
                $items[] = [
                    'id' => $item->getTypeId(),
                    'type' => $this->getTypeName($item->getTypeId()),
                    'quantity' => number_format($item->getQuantity()),
                    'price' => number_format($this->getMarketPrice($item->getTypeId()) * $item->getQuantity())
                ];
            }

            $type = $contract->getType();
            $collateral = null;
            $start = $this->getLocationName($contract->getStartLocationId());
            $end = $this->getLocationName($contract->getEndLocationId());

            switch($type)
            {
                case 'item_exchange':
                case 'auction':
                    $price = number_format($contract->getPrice());
                    break;
                case 'courier':
                    $price = number_format($contract->getReward());
                    $collateral = number_format($contract->getCollateral());
                    break;
                default:
                    $price = "Unknown";
                    break;
            }

            $assignee = null;

            $assignee = $this->getCharacterName($contract->getAssigneeId());

            if ($assignee == "Unknown Character")
            {
                try {
                    $assignee = $this->getCorporationName($contract->getAssigneeId());
                } catch (\Exception $e) {
                    $assignee = "Unknown Assignee";
                }
            }

            $assignee = ($assignee == null) ? "Unknown" : $assignee;

            $out[] = [
                'id' => $contract->getContractId(),
                'issued' => $contract->getDateIssued()->format('Y-m-d H:i'),
                'expired' => $contract->getDateExpired()->format('Y-m-d H:i'),
                'assignee' => $assignee,
                'acceptor' => $this->getCharacterName($contract->getAcceptorId()),
                'issuer' => $this->getCharacterName($contract->getIssuerId()),
                'type' => ucwords(implode(' ', explode('_', $type))),
                'status' => ucwords(implode(' ', explode('_', $contract->getStatus()))),
                'price' => $price,
                'start' => $start,
                'end' => $end,
                'collateral' => $collateral,
                'title' => $contract->getTitle(),
                'items' => $items,
                'volume' => number_format($contract->getVolume()),
            ];
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($contracts));

        return $out;
    }

    /**
     * Given a location ID, figure out what type it is and return the name
     *
     * @param $id
     * @return mixed|string
     */
    private function getLocationName($id)
    {
        $cache_key = "user_location_{$this->char_id}_{$id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        if ($id >= 60000000 && $id <= 64000000)
        {
            try {
                $res = $this->getStationName($id);
                Cache::add($cache_key, $res, env('CACHE_TIME', 3264));
                return $res;
            } catch (\Exception $e) { }
        }
        else if ($id == 2004)
            return "Asset Safety";
        else if ($id >= 40000000 && $id <= 50000000)
            return "Deleted PI Structure";

        try {
            $res = $this->getStructureName($id);
            Cache::add($cache_key, $res, env('CACHE_TIME', 3264));
            return $res;
        } catch (\Exception $e) { }

        Cache::add($cache_key, "Unknown Location", env('CACHE_TIME', 3264));
        return "Unknown Location";
    }

    /**
     * Get a user's journal transactions
     *
     * @param int $page
     * @return array|mixed
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getJournal($page = 1)
    {
        $cache_key = "wallet_journal_{$this->char_id}";
        $out = [];

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new WalletApi($this->client, $this->config);
        $journal = $model->getCharactersCharacterIdWalletJournalWithHttpInfo($this->char_id, $this->char_id, null, $page);

        for ($i = 2; $i <= $journal[2]['X-Pages'][0]; $i++)
            $journal[0] = array_merge($journal[0], $model->getCharactersCharacterIdWallet($this->char_id, $this->char_id, null, $i));

        foreach ($journal[0] as $entry)
        {
            $out[] = [
                'sender' => $this->getUnknownTypeName($entry->getFirstPartyId()),
                'receiver' => $this->getUnknownTypeName($entry->getSecondPartyId()),
                'description' => $entry->getDescription(),
                'type' => ucwords(str_replace('_', ' ', $entry->getRefType())),
                'amount' => number_format($entry->getAmount()),
                'balance' => number_format($entry->getBalance()),
                'date' => $entry->getDate()->format('Y-m-d H:i:s'),
                'note' => $entry->getReason()
            ];
        }

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($journal));
        return $out;
    }

    /**
     * Get a user's skillpoints
     *
     * @return mixed|string
     */
    public function getSkillpoints()
    {
        $cache_key = "skillpoints_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new SkillsApi($this->client, $this->config);
        try {
            $sp = $model->getCharactersCharacterIdSkillsWithHttpInfo($this->char_id, $this->char_id);
        } catch(ApiException $e) {
            return null;
        }

        $out = number_format($sp[0]->getTotalSp());

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($sp));

        return $out;
    }

    /**
     * Get a race name given an ID
     *
     * @param $race_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getRace($race_id)
    {
        $cache_key = "races";

        if (Cache::has($cache_key))
            $races = Cache::get($cache_key);
        else
        {
            $res = $this->eseye->invoke('get', '/universe/races');
            $races = json_decode($res->raw);
            Cache::add($cache_key, $races, env('CACHE_TIME', 3264));
        }

        foreach ($races as $race)
            if ($race->race_id == $race_id)
                return $race->name;

        return 'UNKNOWN';
    }

    /**
     * Get an anestry name given an ID
     *
     * @param $ancestry_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getAncestry($ancestry_id)
    {
        $cache_key = "ancestries";

        if (Cache::has($cache_key))
            $ancestries = Cache::get($cache_key);
        else
        {
            $res = $this->eseye->invoke('get', '/universe/ancestries');
            $ancestries = json_decode($res->raw);
            Cache::add($cache_key, $ancestries, env('CACHE_TIME', 3264));
        }

        foreach($ancestries as $ancestry)
            if ($ancestry->id == $ancestry_id)
                return $ancestry->name;

        return "UNKNOWN";
    }

    /**
     * Get a bloodline name given an ID
     *
     * @param $bloodline_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getBloodline($bloodline_id)
    {
        $cache_key = "bloodlines";

        if (Cache::has($cache_key))
            $bloodlines = Cache::get($cache_key);
        else
        {
            $res = $this->eseye->invoke('get', '/universe/bloodlines/');
            $bloodlines = json_decode($res->raw);
            Cache::add($cache_key, $bloodlines, env('CACHE_TIME', 3264));
        }

        foreach ($bloodlines as $bloodline)
            if ($bloodline->bloodline_id == $bloodline_id)
                return $bloodline->name;

        return "UNKNOWN";
    }

    /**
     * Get a user's contacts
     *
     * @return \Swagger\Client\Eve\Model\GetCharactersCharacterIdContacts200Ok[]
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getContacts()
    {
        $model = new ContactsApi($this->client, $this->config);
        $contacts = $model->getCharactersCharacterIdContacts($this->char_id, $this->char_id);

        foreach ($contacts as $contact)
        {
            switch($contact->getContactType())
            {
                case "character":
                    $contact->contact_name = $this->getCharacterName($contact->getContactId());
                    break;

                case "alliance":
                    $contact->contact_name = $this->getAllianceName($contact->getContactId());
                    break;

                case "corporation":
                    $contact->contact_name = $this->getCorporationName($contact->getContactId());
                    break;

                default:
                    $contact->contact_name = null;
                    break;
            }
        }

        // Reverse sort by standing
        usort($contacts, function($a, $b) {
            $a_standing = $a->getStanding();
            $b_standing = $b->getStanding();

            if ($a_standing == $b_standing)
                return 0;

            return ($a_standing > $b_standing) ? -1 : 1;
        });

        return $contacts;
    }

    /**
     * Get a mailing list ID from the name
     *
     * @param $mailing_list_id
     * @return mixed
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getMailingListName($mailing_list_id)
    {
        $cache_key_base = "mailing_list_";
        $cache_key = "mailing_list_$mailing_list_id";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new MailApi($this->client, $this->config);
        $lists = $model->getCharactersCharacterIdMailListsWithHttpInfo($this->char_id, $this->char_id);

        foreach ($lists[0] as $list)
        {
            $temp_key = $cache_key_base . $list->getMailingListId();
            if (!Cache::has($temp_key))
                Cache::add($temp_key, $list->getName(), $this->getCacheExpirationTime($lists));
        }

        return Cache::get($cache_key);
    }

    /**
     * Get the name of an alliance
     *
     * @param $alliance_id
     * @return string|null
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getAllianceName($alliance_id)
    {
        if ($alliance_id == null)
            return null;

        $cache_key = "alliance_{$alliance_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $alliance_info = $this->eseye->invoke('get', '/alliances/{alliance_id}/', [
            'alliance_id' => $alliance_id
        ]);

        Cache::add($cache_key, $alliance_info->name, env('CACHE_TIME', 3264));

        return $alliance_info->name;
    }

    /**
     * Get the name of a corporation
     *
     * @param $corporation_id
     * @return |null
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getCorporationName($corporation_id)
    {
        if ($corporation_id == null)
            return null;

        $cache_key = "corporation_{$corporation_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $corp_info = $this->eseye->invoke('get', '/corporations/{corporation_id}/', [
            'corporation_id' => $corporation_id
        ]);

        Cache::add($cache_key, $corp_info->name, env('CACHE_TIME', 3264));

        return $corp_info->name;
    }

    /**
     * Get a character name given an ID
     *
     * @param $character_id
     * @return mixed
     */
    public function getCharacterName($character_id)
    {
        if ($character_id == null)
            return null;

        $cache_key = "character_{$character_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        try {
            $char = $this->eseye->invoke('get', '/characters/{character_id}/', [
                'character_id' => $character_id
            ]);
        } catch(\Exception $e) {
            return "Unknown Character";
        }


        Cache::add($cache_key, $char->name, env('CACHE_TIME', 3264));

        return $char->name;
    }

    /**
     * Get a structure name based on the type
     *
     * @param $type
     * @param $id
     * @return mixed|string|null
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getLocationBasedOnStationType($type, $id)
    {
        switch($type)
        {
            case "structure":
                return $this->getStructureName($id);
                break;

            case "station":
                return $this->getStationName($id);
                break;

            default:
                return null;
        }
    }

    /**
     * Get the name of a structure
     * @param $structure_id
     * @return string
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getStructureName($structure_id)
    {
        $cache_key = "structure_{$structure_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new UniverseApi($this->client, $this->config);
        $res = $model->getUniverseStructuresStructureId($structure_id, $this->char_id)->getName();

        Cache::add($cache_key, $res, env('CACHE_TIME', 3264));
        return $res;
    }

    /**
     * Get a system name given the ID
     *
     * @param $system_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getSystemName($system_id)
    {
        $cache_key = "system_{$system_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $res = $this->eseye->invoke('get', '/universe/systems/{system_id}/', [
            'system_id' => $system_id
        ]);

        Cache::add($cache_key, $res->name, env('CACHE_TIME', 3264));

        return $res->name;
    }

    /**
     * Get a region name, given the system ID
     * @param $system_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getRegionName($system_id)
    {
        $cache_key = "system_region_{$system_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $system = $this->eseye->invoke('get', '/universe/systems/{system_id}/', [
            'system_id' => $system_id
        ]);
        $constellation = $this->eseye->invoke('get', '/universe/constellations/{constellation_id}/', [
            'constellation_id' => $system->constellation_id
        ]);
        $region = $this->eseye->invoke('get', '/universe/regions/{region_id}/', [
            'region_id' => $constellation->region_id
        ]);

        Cache::add($cache_key, $region->name, env('CACHE_TIME', 3264));

        return $region->name;
    }

    /**
     * Get a station name, given the ID
     *
     * @param $station_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getStationName($station_id)
    {
        $cache_key = "station_{$station_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $res = $this->eseye->invoke('get', '/universe/stations/{station_id}/', [
            'station_id' => $station_id
        ]);

        Cache::add($cache_key, $res->name, env('CACHE_TIME', 3264));

        return $res->name;
    }

    /**
     * Given a type ID, get its name
     *
     * @param $type_id
     * @return mixed
     */
    public function getTypeName($type_id)
    {
        $dbItem = Type::where('typeID', $type_id)->first();

        if (!$dbItem)
            return null;

        return $dbItem->typeName;
    }

    /**
     * Get the name of a group, given an item ID
     *
     * @param $typeId
     * @return Group|mixed
     */
    public function getGroupName($typeId)
    {
        $item = Type::where('typeID', $typeId)->first();

        if (!$item)
            return null;

        $group = Group::where('groupID', $item->groupID)->first();

        if (!$group)
            return null;

        return $group->groupName;
    }

    /**
     * Given a name ID get the name
     *
     * @param $name_id
     * @return \Swagger\Client\Eve\Model\PostUniverseNames200Ok[]
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getUnknownTypeName($name_id)
    {
        if (!$name_id)
            return null;

        $cache_key = "universe_names_{$name_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $res = $this->eseye->setBody([$name_id])->invoke('post', '/universe/names/');

        if (!$res)
            return null;

        $data = json_decode($res->raw);
        $name = $data[0]->name;
        Cache::add($cache_key, $name, env('CACHE_TIME', 3264));
        return $name;
    }

    /**
     * Get the cache key expiration seconds
     * Takes the array output of the model function *withHTTPInfo()
     * @param array $time
     * @return mixed
     */
    public function getCacheExpirationTime($time)
    {
        $time = $time[2]['Expires'][0];
        $time = Carbon::parse($time);
        return $time->diffInMinutes(now());
    }
}