<?php

namespace App\Connectors;

use App\Models\Group;
use App\Models\Type;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Seat\Eseye\Eseye;
use Swagger\Client\Eve\Api\ClonesApi;
use Swagger\Client\Eve\Api\ContactsApi;
use Swagger\Client\Eve\Api\LocationApi;
use Swagger\Client\Eve\Api\MailApi;
use Swagger\Client\Eve\Api\SkillsApi;
use Swagger\Client\Eve\Api\UniverseApi;
use Swagger\Client\Eve\Configuration;

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

        $this->eseye = new Eseye();
        $this->config = $config;
        $this->char_id = $char_id;
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
            // TODO: Error handling?
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
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getCharacterInfo()
    {
        $locationModel = new LocationApi(null, $this->config);
        $location = $locationModel->getCharactersCharacterIdLocation($this->char_id, $this->char_id);

        if ($location->getStructureId() == null && $location->getStationId() == null)
            $location->structure_name = "In Space (" . $this->getSystemName($location->getSolarSystemId()) . ")";
        else if ($location->getStructureId() != null)
            $location->structure_name = $this->getStructureName($location->getStructureId());
        else
            $location->structure_name = $this->getStationName($location->getStationId());

        $ship = $locationModel->getCharactersCharacterIdShip($this->char_id, $this->char_id);

        if (Cache::has('public_data_' . $this->char_id))
            $public_data = Cache::get('public_data_' . $this->char_id);
        else
        {
            $public_data = $this->eseye->invoke('get', '/characters/{character_id}/', [
                "character_id" => $this->char_id
            ]);
            Cache::add('public_data_' . $this->char_id, $public_data, env('CACHE_TIME', 3264));
        }

        return [
            'location' => $location,
            'birthday' => explode('T', $public_data->birthday)[0],
            'gender' => ucfirst($public_data->gender),
            'ancestry' => $this->getAncestry($public_data->ancestry_id),
            'bloodline' => $this->getBloodline($public_data->bloodline_id),
            'race' => $this->getRace($public_data->race_id),
            'current_ship' => $ship->getShipName() . " (" . $this->getTypeName($ship->getShipTypeId()) . ")",
            'security_status' => round($public_data->security_status, 4),
            'region' => $this->getRegionName($location->getSolarSystemId())
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
        $model = new ClonesApi(null, $this->config);

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
        $model = new MailApi(null, $this->config);
        $mail = $model->getCharactersCharacterIdMail($this->char_id, $this->char_id);

        foreach ($mail as $m)
        {
            if (Cache::has('mail_body_' . $m->getMailId()))
                $m->contents = Cache::get('mail_body_' . $m->getMailId());
            else
            {
                $m->contents = $model->getCharactersCharacterIdMailMailId($this->char_id, $m->getMailId(), $this->char_id)->getBody();
                Cache::add('mail_body_' . $m->getMailId(), $m->contents, env('CACHE_TIME', 3264));
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
                            'name' => $this->getCharacterName($recipient->getRecipientId())
                        ];
                        break;

                    case 'corporation':
                        $m->recipients[] = [
                            'type' => 'corporation',
                            'name' => $this->getCorporationName($recipient->getRecipientId())
                        ];
                        break;

                    case 'alliance':
                        $m->recipients[] = [
                            'type' => 'alliance',
                            'name' => $this->getAllianceName($recipient->getRecipientId())
                        ];
                        break;

                    case 'mailing_list':
                        $m->recipients[] = [
                            'type' => 'mailing list',
                            'name' => $this->getMailingListName($recipient->getRecipientId())
                        ];
                        break;

                    default:
                        break;
                }
            }
        }

        return $mail;
    }

    /**
     * Get a character's skills
     *
     * @return array|mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getSkills()
    {
        $cache_key = "skills_{$this->char_id}";

        if (Cache::has($cache_key))
            return Cache::get($cache_key);

        $model = new SkillsApi(null, $this->config);
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

        Cache::add($cache_key, $out, $this->getCacheExpirationTime($skills));
        return $out;
    }

    /**
     * Get a character's skillpoints
     *
     * @return int
     * @throws \Swagger\Client\Eve\ApiException
     */
    public function getSkillpoints()
    {
        $model = new SkillsApi(null, $this->config);
        $sp = $model->getCharactersCharacterIdSkills($this->char_id, $this->char_id);
        return number_format($sp->getTotalSp());
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
        $res = $this->eseye->invoke('get', '/universe/races');
        $races = json_decode($res->raw);

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
        $res = $this->eseye->invoke('get', '/universe/ancestries');
        $ancestries = json_decode($res->raw);

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
        $res = $this->eseye->invoke('get', '/universe/bloodlines/');
        $bloodlines = json_decode($res->raw);

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
        $model = new ContactsApi(null, $this->config);
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
        if (Cache::has($mailing_list_id))
            return Cache::get($mailing_list_id);

        $model = new MailApi(null, $this->config);
        $lists = $model->getCharactersCharacterIdMailLists($this->char_id, $this->char_id);

        foreach ($lists as $list)
        {
            if (!Cache::has($list->getMailingListId()))
                Cache::add($list->getMailingListId(), $list->getName(), env('CACHE_TIME', 3264));
        }

        return Cache::get($mailing_list_id);
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

        if (Cache::has($alliance_id))
            return Cache::get($alliance_id);

        $alliance_info = $this->eseye->invoke('get', '/alliances/{alliance_id}/', [
            'alliance_id' => $alliance_id
        ]);

        Cache::add($alliance_id, $alliance_info->name, env('CACHE_TIME', 3264));

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

        if (Cache::has($corporation_id))
            return Cache::get($corporation_id);

        $corp_info = $this->eseye->invoke('get', '/corporations/{corporation_id}/', [
            'corporation_id' => $corporation_id
        ]);

        Cache::add($corporation_id, $corp_info->name, env('CACHE_TIME', 3264));

        return $corp_info->name;
    }

    /**
     * Get a character name given an ID
     *
     * @param $character_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getCharacterName($character_id)
    {
        if ($character_id == null)
            return null;

        if (Cache::has($character_id))
            return Cache::get($character_id);

        $char = $this->eseye->invoke('get', '/characters/{character_id}/', [
            'character_id' => $character_id
        ]);

        Cache::add($character_id, $char->name, env('CACHE_TIME', 3264));

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
        if (Cache::has('structure_' . $structure_id))
            return Cache::get('structure_' . $structure_id);

        $model = new UniverseApi(null, $this->config);
        $res = $model->getUniverseStructuresStructureId($structure_id, $this->char_id)->getName();

        Cache::add('structure_' . $structure_id, $res, env('CACHE_TIME', 3264));
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
        if (Cache::has('system_' . $system_id))
            return Cache::get('system_' . $system_id);

        $res = $this->eseye->invoke('get', '/universe/systems/{system_id}/', [
            'system_id' => $system_id
        ]);

        Cache::add('system_' . $system_id, $res->name, env('CACHE_TIME', 3264));

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
        if (Cache::has('system_region_' . $system_id))
            return Cache::get('system_region_' . $system_id);

        $system = $this->eseye->invoke('get', '/universe/systems/{system_id}/', [
            'system_id' => $system_id
        ]);
        $constellation = $this->eseye->invoke('get', '/universe/constellations/{constellation_id}/', [
            'constellation_id' => $system->constellation_id
        ]);
        $region = $this->eseye->invoke('get', '/universe/regions/{region_id}/', [
            'region_id' => $constellation->region_id
        ]);

        Cache::add('system_region_' . $system_id, $region->name, env('CACHE_TIME', 3264));

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
        if (Cache::has('station_' . $station_id))
            return Cache::get('station_' . $station_id);

        $res = $this->eseye->invoke('get', '/universe/stations/{station_id}/', [
            'station_id' => $station_id
        ]);

        Cache::add('station_' . $station_id, $res->name, env('CACHE_TIME', 3264));

        return $res->name;
    }

    /**
     * Given a type ID, get its name
     *
     * @param $type_id
     * @return mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getTypeName($type_id)
    {
        $dbItem = Type::find($type_id);

        if ($dbItem)
            return $dbItem->name;

        $res = $this->eseye->invoke('get', '/universe/types/{type_id}/', [
            'type_id' => $type_id
        ]);

        $dbItem = new Type();
        $dbItem->id = $type_id;
        $dbItem->name = $res->name;
        $dbItem->group_id = $res->group_id;
        $dbItem->save();

        return $res->name;
    }

    /**
     * Get the name of a group, given an item ID
     *
     * @param $id
     * @return Group|mixed
     * @throws \Seat\Eseye\Exceptions\EsiScopeAccessDeniedException
     * @throws \Seat\Eseye\Exceptions\InvalidContainerDataException
     * @throws \Seat\Eseye\Exceptions\UriDataMissingException
     */
    public function getGroupName($itemId)
    {
        $item = Type::find($itemId);

        if (!$item)
        {
            // Add it to the database
            $this->getTypeName($itemId);
            $item = Type::find($itemId);
        }

        $dbItem = Group::find($item->group_id);

        if ($dbItem)
            return $dbItem->name;

        $group_id = $item->group_id;

        $res = $this->eseye->invoke('get', '/universe/groups/{group_id}/', [
            'group_id' => $group_id
        ]);

        $dbItem = new Group();
        $dbItem->id = $group_id;
        $dbItem->name = $res->name;
        $dbItem->save();

        return $dbItem->name;
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