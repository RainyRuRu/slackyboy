<?php
namespace Slackyboy;

use Evenement\EventEmitterTrait;
use Slackyboy\Slack\ApiClient;
use Slackyboy\Slack\Channel;
use Slackyboy\Slack\RealTimeMessagingClient;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Noodlehaus\Config;

/**
 * Main bot object that connects to Slack and emits useful bot-wide events.
 */
class Bot
{
    use EventEmitterTrait;

    protected $config;
    protected $client;
    protected $rtm;
    protected $plugins;
    protected $botUser;

    /**
     * @var Logger A logger for all bot-related logs.
     */
    protected $log;

    /**
     * Creates a new bot instance.
     */
    public function __construct()
    {
        // create a bot-wide log
        $this->log = new Logger('bot');
        $this->log->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

        // load configuration
        $this->loadConfig();

        // configure the log to write to the config-specified location
        $this->log->pushHandler(new StreamHandler($this->config->get('log'), Logger::DEBUG));

        // load plugins
        $this->loadPlugins();

        // create an api client
        $this->client = new ApiClient();
        $this->client->setToken($this->config->get('slack.token'));

        // get the Slack bot user info
        $this->botUser = $this->client->getAuthedUser();
        $this->log->info('Bot user name is configured as '.$this->botUser->getUsername());
    }

    /**
     * Gets the bot logger.
     *
     * @return Logger
     */
    public function getLog()
    {
        return $this->log;
    }

    /**
     * Gets the bot configuration.
     *
     * @return Ccnfig The bot configuration.
     */
    public function getConfig()
    {
        return $this->config;
    }

    public function getSlackClient()
    {
        return $this->client;
    }

    public function loadConfig()
    {
        // if no config file exists, create the default
        if (!is_file($this->getConfigPath())) {
            //$this->createDefaultConfig();
        }

        // load config
        $this->config = new Config(dirname(__DIR__).'/slackyboy.json');
    }

    public function loadPlugins()
    {
        // create plugin manager and load plugins
        $this->plugins = new Plugins\PluginManager($this);

        foreach ($this->config->get('plugins') as $name => $options) {
            $this->plugins->load($name);
        }
    }

    public function run()
    {
        $this->rtm = new RealTimeMessagingClient($this->client);

        $this->rtm->on('message', function ($data) {
            $message = Message::fromData($this->client, $data);

            $this->log->info('Noticed message', [
                'text' => $message->getText(),
            ]);

            $this->emit('message', [$message]);

            if ($message->matchesAny('/'.$this->botUser->getUsername().'/i')) {
                $this->log->debug('Mentioned in message', [$message]);
                $this->emit('mention', [$message]);
            }
        });

        $this->rtm->connect();
        $this->rtm->listen();
    }

    public function say($text, Channel $channel)
    {
        $this->log->info('Sending new message');
        $this->rtm->send($text, $channel);
    }

    public function quit()
    {
        $this->log->info('Quitting now');
        $this->rtm->disconnect();
    }

    public function restart()
    {
        $this->quit();
        $this->log->info('Restarting now');

        global $argv;
        if (!pcntl_fork()) {
            // We only care about the child fork
            pcntl_exec($argv[0], array_slice($argv, 1));
        }
    }

    protected function getConfigPath()
    {
        return getenv('HOME').'/.slackyboy.json';
    }

    protected function createDefaultConfig()
    {
        copy(dirname(__DIR__).'/slackyboy.json', $this->getConfigPath());
    }
}
