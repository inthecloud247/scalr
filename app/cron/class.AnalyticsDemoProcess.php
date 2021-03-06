<?php

namespace AnalyticsDemo {
use Scalr\Modules\PlatformFactory;
use Scalr\Modules\Platforms\Openstack\OpenstackPlatformModule;
use Scalr\Modules\Platforms\Cloudstack\CloudstackPlatformModule;
use Scalr\Model\Entity\CloudLocation;

class stdPricing extends \stdClass
{
    private $cache;
    private $cadb;

    public function __construct()
    {
        $this->cadb = \Scalr::getContainer()->cadb;
    }

    public function getPrice($applied, $platform, $cloudLocation, $instanceType, $url= '', $os = 0)
    {
        $key = sprintf('%s,%s,%s,%s,%s', $instanceType, $applied->format('Y-m-d'), $platform, $cloudLocation, $url);

        if (!isset($this->cache[$key])) {
            $this->cache[$key] = $this->cadb->GetOne("
                SELECT p.cost
                FROM price_history ph
                JOIN prices p ON p.price_id = ph.price_id
                LEFT JOIN price_history ph2 ON ph2.platform = ph.platform
                    AND ph2.cloud_location = ph.cloud_location
                    AND ph2.account_id = ph.account_id
                    AND ph2.url = ph.url
                    AND ph2.applied > ph.applied AND ph2.applied <= ?
                LEFT JOIN prices p2 ON p2.price_id = ph2.price_id
                    AND p2.instance_type = p.instance_type
                    AND p2.os = p.os
                WHERE ph.account_id = ? AND p2.price_id IS NULL
                AND ph.platform = ?
                AND ph.cloud_location = ?
                AND ph.url = ?
                AND ph.applied <= ?
                AND p.instance_type = ?
                AND p.os = ?
                LIMIT 1
            ", [
                $applied->format('Y-m-d'),
                0,
                $platform,
                $cloudLocation,
                $url,
                $applied->format('Y-m-d'),
                $instanceType,
                $os
            ]);

            if (!$this->cache[$key] || $this->cache[$key] <= 0.0001) {
                $this->cache[$key] = .0123;
            }
        }

        return $this->cache[$key];
    }
}

class stdProject extends \stdClass
{
    /**
     * @var \Scalr\Stats\CostAnalytics\Entity\ProjectEntity
     */
    public $project;

    /**
     * @var \AnalyticsDemo\stdCc
     */
    public $cc;

    /**
     * @var \AnalyticsDemo\stdFarm[]
     */
    public $farms;

    /**
     * @var int
     */
    public $number;
}

class stdCc extends \stdClass
{
    /**
     * @var \Scalr\Stats\CostAnalytics\Entity\CostCentreEntity;
     */
    public $cc;

    /**
     * @var int
     */
    public $number;
}

class stdFarm extends \stdClass
{
    /**
     * @var \DBFarm
     */
    public $farm;

    /**
     * @var \AnalyticsDemo\stdProject
     */
    public $project;

    /**
     * @var \AnalyticsDemo\stdCc
     */
    public $cc;

    /**
     * @var \AnalyticsDemo\stdEnv;
     */
    public $env;

    /**
     * @var \AnalyticsDemo\stdFarmRole[]
     */
    public $farmRoles;

}

class stdFarmRole extends \stdClass
{
    /**
     * @var \DBFarmRole
     */
    public $farmRole;

    /**
     * @var \AnalyticsDemo\stdFarm
     */
    public $farm;

    /**
     * @var int
     */
    public $min;

    /**
     * @var int
     */
    public $max;

    /**
     * @var string
     */
    private $instanceType;

    /**
     * Gets instanceType
     *
     * @return  string
     */
    public function getInstanceType()
    {
        if ($this->instanceType === null) {
            $property = 'aws.instance_type';

            if ($this->farmRole->Platform == \SERVER_PLATFORMS::EC2) {
                $property = 'aws.instance_type';
            } else if (PlatformFactory::isOpenstack($this->farmRole->Platform)) {
                $property = 'openstack.flavor-id';
            } else if (PlatformFactory::isCloudstack($this->farmRole->Platform)) {
                $property = 'cloudstack.service_offering_id';
            } else if ($this->farmRole->Platform == \SERVER_PLATFORMS::GCE) {
                $property = 'gce.machine-type';
            }

            $this->instanceType = $this->farmRole->GetSetting($property);
        }

        return $this->instanceType;
    }
}

class stdEnv extends \stdClass
{
    /**
     * @var \Scalr_Environment
     */
    public $env;

    /**
     * @var \AnalyticsDemo\stdFarm[]
     */
    public $farms;

    /**
     * List of url for an each platform
     * @var array
     */
    public $aUrl;

    /**
     * Gets a normalized url for an each platform
     *
     * @return   string Returns url
     */
    public function getUrl($platform)
    {
        if (!isset($this->aUrl[$platform])) {
            if ($platform == \SERVER_PLATFORMS::EC2) {
                $value = '';
            } else if (PlatformFactory::isOpenstack($platform)) {
                $value = CloudLocation::normalizeUrl(
                    $this->env->getPlatformConfigValue($platform . '.' . OpenstackPlatformModule::KEYSTONE_URL)
                );
            } else if (PlatformFactory::isCloudstack($platform)) {
                $value = CloudLocation::normalizeUrl(
                    $this->env->getPlatformConfigValue($platform . '.' . CloudstackPlatformModule::API_URL)
                );
            } else if ($platform == \SERVER_PLATFORMS::GCE) {
                $value = '';
            }

            $this->aUrl[$platform] = $value;
        }

        return $this->aUrl[$platform];
    }
}
}

namespace {
use Scalr\Upgrade\Console;
use \DateTime, \DateTimeZone;
use Scalr\Stats\CostAnalytics\Entity\UsageHourlyEntity;
use Scalr\Stats\CostAnalytics\Entity\PriceEntity;
use Scalr\Stats\CostAnalytics\Quarters;
use Scalr\Stats\CostAnalytics\Entity\SettingEntity;
use Scalr\Stats\CostAnalytics\Entity\QuarterlyBudgetEntity;

// Use once to initialize for three months
// update price_history set applied = applied - INTERVAL 3 MONTH;
// define("SCALR_ANALYTICS_DEMO_PAST_HOURS_INIT", 24 * 30 * 3);

// Hourly
define("SCALR_ANALYTICS_DEMO_PAST_HOURS_INIT", 0);

set_time_limit(0);

class AnalyticsDemoProcess implements \Scalr\System\Pcntl\ProcessInterface
{
    const DELETE_LIMIT = 1000;
    const SLEEP_TIMEOUT = 60;

    public $ThreadArgs;
    public $ProcessDescription = "Rotate logs table";
    public $Logger;
    public $IsDaemon;

    /**
     * @var Console
     */
    private $console;

    public function __construct()
    {
        $this->Logger = Logger::getLogger(__CLASS__);
        $this->console = new Console();
        $this->console->timeformat = 'H:i:s';
    }

    /**
     * {@inheritdoc}
     * @see \Scalr\System\Pcntl\ProcessInterface::OnStartForking()
     */
    public function OnStartForking()
    {
        if (!\Scalr::getContainer()->analytics->enabled) {
            die("CA has not been enabled in config!\n");
        }

        $db = \Scalr::getDb();
        $cadb = \Scalr::getContainer()->cadb;

        $pricing = new \AnalyticsDemo\stdPricing();
        $quarters = new Quarters(SettingEntity::getQuarters());

        $logger = \Scalr::getContainer()->logger('initdemo');
        $logger->setLevel('INFO');

        $logger->info('Started AnalyticsDemo process');

        $tzUtc = new DateTimeZone('UTC');

        /* @var $projects \AnalyticsDemo\stdProject[] */
        $projects = [];

        /* @var $ccs \AnalyticsDemo\stdCc[] */
        $ccs = [];

        /* @var $farms \AnalyticsDemo\stdFarm[] */
        $farms = [];

        /* @var $environments \AnalyticsDemo\stdEnv[] */
        $environments = [];

        /* @var $farmRoles \AnalyticsDemo\stdFarmRole[] */
        //$farmRoles = [];

        //Analytics container
        $analytics = \Scalr::getContainer()->analytics;

        $logger->debug('CC & PROJECTS ---');

        foreach ($analytics->ccs->all(true) as $cc) {
            /* @var $cc \Scalr\Stats\CostAnalytics\Entity\CostCentreEntity */
            $co = new \AnalyticsDemo\stdCc();
            $co->cc = $cc;

            $ccs[$cc->ccId] = $co;

            $logger->debug("Cost center: '%s'", $cc->name);

            foreach ($cc->getProjects() as $project) {
                /* @var $project \Scalr\Stats\CostAnalytics\Entity\ProjectEntity */
                $project->loadProperties();

                $po = new \AnalyticsDemo\stdProject();
                $po->project = $project;
                $po->cc = $co;

                $projects[$project->projectId] = $po;
                $logger->debug("-- Project: '%s'", $project->name);
            }
        }

        //Ordering cost centers
        $number = 0;
        foreach ($ccs as $obj) {
            $obj->number = $number++;
        }
        //Ordering projects
        $number = 0;
        foreach ($projects as $obj) {
            $obj->number = $number++;
        }

        $logger->debug("FARMS ---");

        $pastIterations = SCALR_ANALYTICS_DEMO_PAST_HOURS_INIT;

        //Current time
        $dt = new DateTime('now', $tzUtc);
        do {
            $timestamp = $dt->format('Y-m-d H:00:00');
            $period = $quarters->getPeriodForDate($dt->format('Y-m-d'));

            $logger->info("Processing time:%s, year:%d, quarter:%d", $timestamp, $period->year, $period->quarter);

            //Gets farms for each project
            foreach ($projects as $po) {
                foreach ($analytics->projects->getFarmsList($po->project->projectId) as $farmId => $farmName) {
                    if (!isset($farms[$farmId])) {
                        $fo = new \AnalyticsDemo\stdFarm();
                        $fo->farm = \DBFarm::LoadByID($farmId);
                        $fo->project = $po;
                        $fo->cc = $po->cc;

                        //$po->farms[] = $fo;
                        $farms[$farmId] = $fo;

                        if (!isset($environments[$fo->farm->EnvID])) {
                            $eo = new \AnalyticsDemo\stdEnv();
                            $eo->env = $fo->farm->getEnvironmentObject();
                            //$eo->farms = [$farmId => $fo];
                            $environments[$fo->farm->EnvID] = $eo;
                            $fo->env = $eo;
                        } else {
                            //$environments[$fo->farm->EnvID]->farms[$farmId] = $fo;
                            $fo->env = $environments[$fo->farm->EnvID];
                        }

                        $fo->farmRoles = [];

                        foreach ($fo->farm->GetFarmRoles() as $farmRole) {
                            $fro = new \AnalyticsDemo\stdFarmRole();
                            $fro->farmRole = $farmRole;
                            $fro->farm = $fo;
                            $fro->min = $farmRole->GetSetting(\DBFarmRole::SETTING_SCALING_MIN_INSTANCES);
                            $fro->max = $farmRole->GetSetting(\DBFarmRole::SETTING_SCALING_MAX_INSTANCES);

                            $fo->farmRoles[$farmRole->ID] = $fro;
                            //$farmRoles[$farmRole->ID] = $fro;
                        }
                    } else {
                        $fo = $farms[$farmId];
                    }

                    $logger->debug("Farm:'%s':%d from Env:'%s':%d corresponds to Project:'%s' -> CC:'%s'",
                        $fo->farm->Name, $fo->farm->ID,
                        $fo->farm->getEnvironmentObject()->name, $fo->farm->EnvID,
                        $po->project->name, $po->cc->cc->name);

                    foreach ($fo->farmRoles as $fro) {
                        $countInstances = rand(max(1, floor($fro->max * 0.7)), min((int)$fro->max, 2));

                        //!FIXME os is linux always
                        $cost = $pricing->getPrice(
                            $dt,
                            $fro->farmRole->Platform,
                            $fro->farmRole->CloudLocation,
                            $fro->getInstanceType(),
                            $fo->env->getUrl($fro->farmRole->Platform),
                            PriceEntity::OS_LINUX
                        );

                        //Hourly usage
                        $rec = new UsageHourlyEntity();
                        $rec->usageId = \Scalr::GenerateUID();
                        $rec->accountId = $fro->farm->farm->ClientID;
                        $rec->ccId = $po->cc->cc->ccId;
                        $rec->projectId = $po->project->projectId;
                        $rec->cloudLocation = $fro->farmRole->CloudLocation;
                        $rec->dtime = new DateTime($timestamp, $tzUtc);
                        $rec->envId = $fo->farm->EnvID;
                        $rec->farmId = $fo->farm->ID;
                        $rec->farmRoleId = $fro->farmRole->ID;
                        $rec->instanceType = $fro->getInstanceType();
                        $rec->platform = $fro->farmRole->Platform;
                        $rec->url = $fo->env->getUrl($fro->farmRole->Platform);
                        $rec->os = PriceEntity::OS_LINUX;
                        $rec->num = $countInstances;
                        $rec->cost = $cost * $countInstances;

                        $rec->save();

                        $logger->log((SCALR_ANALYTICS_DEMO_PAST_HOURS_INIT > 0 ? 'DEBUG' : 'INFO'),
                            "-- role:'%s':%d platform:%s, min:%d - max:%d, cloudLocation:'%s', instanceType:'%s', "
                          . "cost:%0.4f * %d = %0.3f",
                            $fro->farmRole->Alias, $fro->farmRole->ID, $fro->farmRole->Platform,
                            $fro->min, $fro->max,
                            $fro->farmRole->CloudLocation,
                            $fro->getInstanceType(),
                            $cost, $countInstances,
                            $rec->cost);

                        //Update Daily table
                        $cadb->Execute("
                            INSERT usage_d
                            SET date = ?,
                                platform = ?,
                                cc_id = UNHEX(?),
                                project_id = UNHEX(?),
                                farm_id = ?,
                                env_id = ?,
                                cost = ?
                            ON DUPLICATE KEY UPDATE cost = cost + ?
                        ", [
                            $rec->dtime->format('Y-m-d'),
                            $rec->platform,
                            ($rec->ccId ? str_replace('-', '', $rec->ccId) : '00000000-0000-0000-0000-000000000000'),
                            ($rec->projectId ? str_replace('-', '', $rec->projectId) : '00000000-0000-0000-0000-000000000000'),
                            ($rec->farmId ? $rec->farmId : 0),
                            ($rec->envId ? $rec->envId : 0),
                            $rec->cost,
                            $rec->cost,
                        ]);

                        //Updates Quarterly Budget
                        if ($rec->ccId) {
                            $cadb->Execute("
                                INSERT quarterly_budget
                                SET year = ?,
                                    subject_type = ?,
                                    subject_id = UNHEX(?),
                                    quarter = ?,
                                    budget = 1000,
                                    cumulativespend = ?
                                ON DUPLICATE KEY UPDATE cumulativespend = cumulativespend + ?
                            ", [
                                $period->year,
                                QuarterlyBudgetEntity::SUBJECT_TYPE_CC,
                                str_replace('-', '', $rec->ccId),
                                $period->quarter,
                                $rec->cost,
                                $rec->cost,
                            ]);
                        }

                        if ($rec->projectId) {
                            $cadb->Execute("
                                INSERT quarterly_budget
                                SET year = ?,
                                    subject_type = ?,
                                    subject_id = UNHEX(?),
                                    quarter = ?,
                                    budget = 1000,
                                    cumulativespend = ?
                                ON DUPLICATE KEY UPDATE cumulativespend = cumulativespend + ?
                            ", [
                                $period->year,
                                QuarterlyBudgetEntity::SUBJECT_TYPE_PROJECT,
                                str_replace('-', '', $rec->projectId),
                                $period->quarter,
                                $rec->cost,
                                $rec->cost,
                            ]);
                        }
                    }

                    unset($fo);
                }
            }

            $dt->modify('-1 hour');
        } while ($pastIterations-- > 0);

        $logger->info("Finished AnalyticsDemo process");
        $logger->info("Memory usage: %0.3f Mb", memory_get_usage() / 1024 / 1024);
    }

    /**
     * {@inheritdoc}
     * @see \Scalr\System\Pcntl\ProcessInterface::OnEndForking()
     */
    public function OnEndForking()
    {
    }

    /**
     * {@inheritdoc}
     * @see \Scalr\System\Pcntl\ProcessInterface::StartThread()
     */
    public function StartThread($farminfo)
    {
    }
}
}
