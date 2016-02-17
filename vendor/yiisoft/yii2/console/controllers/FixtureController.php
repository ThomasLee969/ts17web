<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\console\controllers;

use Yii;
use yii\console\Controller;
use yii\console\Exception;
use yii\helpers\Console;
use yii\helpers\FileHelper;
use yii\test\FixtureTrait;

/**
 * Manages fixture data loading and unloading.
 *
 * ~~~
 * #load fixtures from UsersFixture class with default namespace "tests\unit\fixtures"
 * yii fixture/load User
 *
 * #also a short version of this command (generate action is default)
 * yii fixture User
 *
 * #load all fixtures
 * yii fixture "*"
 *
 * #load all fixtures except User
 * yii fixture "*" -User
 *
 * #load fixtures with different namespace.
 * yii fixture/load User --namespace=alias\my\custom\namespace\goes\here
 * ~~~
 *
 * The `unload` sub-command can be used similarly to unload fixtures.
 *
 * @author Mark Jebri <mark.github@yandex.ru>
 * @since 2.0
 */
class FixtureController extends Controller
{
    use FixtureTrait;

    /**
     * @var string controller default action ID.
     */
    public $defaultAction = 'load';
    /**
     * @var string default namespace to search fixtures in
     */
    public $namespace = 'tests\unit\fixtures';
    /**
     * @var array global fixtures that should be applied when loading and unloading. By default it is set to `InitDbFixture`
     * that disables and enables integrity check, so your data can be safely loaded.
     */
    public $globalFixtures = [
        'yii\test\InitDb',
    ];


    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'namespace', 'globalFixtures'
        ]);
    }

    /**
     * Loads the specified fixture data.
     * For example,
     *
     * ~~~
     * # load the fixture data specified by User and UserProfile.
     * # any existing fixture data will be removed first
     * yii fixture/load User UserProfile
     *
     * # load all available fixtures found under 'tests\unit\fixtures'
     * yii fixture/load "*"
     *
     * # load all fixtures except User and UserProfile
     * yii fixture/load "*" -User -UserProfile
     * ~~~
     *
     * @throws Exception if the specified fixture does not exist.
     */
    public function actionLoad()
    {
        $fixturesInput = func_get_args();
        if ($fixturesInput === []) {
            $this->stdout($this->getHelpSummary() . "\n");

            $helpCommand = Console::ansiFormat("yii help fixture", [Console::FG_CYAN]);
            $this->stdout("Use $helpCommand to get usage info.\n");

            return self::EXIT_CODE_NORMAL;
        }

        $filtered = $this->filterFixtures($fixturesInput);
        $except = $filtered['except'];

        if (!$this->needToApplyAll($fixturesInput[0])) {

            $fixtures = $filtered['apply'];

            $foundFixtures = $this->findFixtures($fixtures);
            $notFoundFixtures = array_diff($fixtures, $foundFixtures);

            if ($notFoundFixtures) {
                $this->notifyNotFound($notFoundFixtures);
            }

        } else {
            $foundFixtures = $this->findFixtures();
        }

        $fixturesToLoad = array_diff($foundFixtures, $except);

        if (!$foundFixtures) {
            throw new Exception(
                "No files were found by name: \"" . implode(', ', $fixturesInput) . "\".\n" .
                "Check that files with these name exists, under fixtures path: \n\"" . $this->getFixturePath() . "\"."
            );
        }

        if (!$fixturesToLoad) {
            $this->notifyNothingToLoad($foundFixtures, $except);
            return static::EXIT_CODE_NORMAL;
        }

        if (!$this->confirmLoad($fixturesToLoad, $except)) {
            return static::EXIT_CODE_NORMAL;
        }

        $fixtures = $this->getFixturesConfig(array_merge($this->globalFixtures, $fixturesToLoad));

        if (!$fixtures) {
            throw new Exception('No fixtures were found in namespace: "' . $this->namespace . '"' . '');
        }

        $fixturesObjects = $this->createFixtures($fixtures);

        $this->unloadFixtures($fixturesObjects);
        $this->loadFixtures($fixturesObjects);
        $this->notifyLoaded($fixtures);

        return static::EXIT_CODE_NORMAL;
    }

    /**
     * Unloads the specified fixtures.
     * For example,
     *
     * ~~~
     * # unload the fixture data specified by User and UserProfile.
     * yii fixture/unload User UserProfile
     *
     * # unload all fixtures found under 'tests\unit\fixtures'
     * yii fixture/unload "*"
     *
     * # unload all fixtures except User and UserProfile
     * yii fixture/unload "*" -User -UserProfile
     * ~~~
     *
     * @throws Exception if the specified fixture does not exist.
     */
    public function actionUnload()
    {
        $fixturesInput = func_get_args();
        $filtered = $this->filterFixtures($fixturesInput);
        $except = $filtered['except'];

        if (!$this->needToApplyAll($fixturesInput[0])) {

            $fixtures = $filtered['apply'];

            $foundFixtures = $this->findFixtures($fixtures);
            $notFoundFixtures = array_diff($fixtures, $foundFixtures);

            if ($notFoundFixtures) {
                $this->notifyNotFound($notFoundFixtures);
            }

        } else {
            $foundFixtures = $this->findFixtures();
        }

        $fixturesToUnload = array_diff($foundFixtures, $except);

        if (!$foundFixtures) {
            throw new Exception(
                "No files were found by name: \"" . implode(', ', $fixturesInput) . "\".\n" .
                "Check that files with these name exists, under fixtures path: \n\"" . $this->getFixturePath() . "\"."
            );
        }

        if (!$fixturesToUnload) {
            $this->notifyNothingToUnload($foundFixtures, $except);
            return static::EXIT_CODE_NORMAL;
        }

        if (!$this->confirmUnload($fixturesToUnload, $except)) {
            return static::EXIT_CODE_NORMAL;
        }

        $fixtures = $this->getFixturesConfig(array_merge($this->globalFixtures, $fixturesToUnload));

        if (!$fixtures) {
            throw new Exception('No fixtures were found in namespace: ' . $this->namespace . '".');
        }

        $this->unloadFixtures($this->createFixtures($fixtures));
        $this->notifyUnloaded($fixtures);
    }

    /**
     * Notifies user that fixtures were successfully loaded.
     * @param array $fixtures
     */
    private function notifyLoaded($fixtures)
    {
        $this->stdout("Fixtures were successfully loaded from namespace:\n", Console::FG_YELLOW);
        $this->stdout("\t\"" . Yii::getAlias($this->namespace) . "\"\n\n", Console::FG_GREEN);
        $this->outputList($fixtures);
    }

    /**
     * Notifies user that there are no fixtures to load according input conditions
     * @param array $foundFixtures array of found fixtures
     * @param array $except array of names of fixtures that should not be loaded
     */
    public function notifyNothingToLoad($foundFixtures, $except)
    {
        $this->stdout("Fixtures to load could not be found according given conditions:\n\n", Console::FG_RED);
        $this->stdout("Fixtures namespace is: \n", Console::FG_YELLOW);
        $this->stdout("\t" . $this->namespace . "\n", Console::FG_GREEN);

        if (count($foundFixtures)) {
            $this->stdout("\nFixtures founded under the namespace:\n\n", Console::FG_YELLOW);
            $this->outputList($foundFixtures);
        }

        if (count($except)) {
            $this->stdout("\nFixtures that will NOT be loaded: \n\n", Console::FG_YELLOW);
            $this->outputList($except);
        }
    }

    /**
     * Notifies user that there are no fixtures to unload according input conditions
     * @param array $foundFixtures array of found fixtures
     * @param array $except array of names of fixtures that should not be loaded
     */
    public function notifyNothingToUnload($foundFixtures, $except)
    {
        $this->stdout("Fixtures to unload could not be found according given conditions:\n\n", Console::FG_RED);
        $this->stdout("Fixtures namespace is: \n", Console::FG_YELLOW);
        $this->stdout("\t" . $this->namespace . "\n", Console::FG_GREEN);

        if (count($foundFixtures)) {
            $this->stdout("\nFixtures founded under the namespace:\n\n", Console::FG_YELLOW);
            $this->outputList($foundFixtures);
        }

        if (count($except)) {
            $this->stdout("\nFixtures that will NOT be unloaded: \n\n", Console::FG_YELLOW);
            $this->outputList($except);
        }
    }

    /**
     * Notifies user that fixtures were successfully unloaded.
     * @param array $fixtures
     */
    private function notifyUnloaded($fixtures)
    {
        $this->stdout("\nFixtures were successfully unloaded from namespace: ", Console::FG_YELLOW);
        $this->stdout(Yii::getAlias($this->namespace) . "\"\n\n", Console::FG_GREEN);
        $this->outputList($fixtures);
    }

    /**
     * Notifies user that fixtures were not found under fixtures path.
     * @param array $fixtures
     */
    private function notifyNotFound($fixtures)
    {
        $this->stdout("Some fixtures were not found under path:\n", Console::BG_RED);
        $this->stdout("\t" . $this->getFixturePath() . "\n\n", Console::FG_GREEN);
        $this->stdout("Check that they have correct namespace \"{$this->namespace}\" \n", Console::BG_RED);
        $this->outputList($fixtures);
        $this->stdout("\n");
    }

    /**
     * Prompts user with confirmation if fixtures should be loaded.
     * @param array $fixtures
     * @param array $except
     * @return boolean
     */
    private function confirmLoad($fixtures, $except)
    {
        $this->stdout("Fixtures namespace is: \n", Console::FG_YELLOW);
        $this->stdout("\t" . $this->namespace . "\n\n", Console::FG_GREEN);

        if (count($this->globalFixtures)) {
            $this->stdout("Global fixtures will be used:\n\n", Console::FG_YELLOW);
            $this->outputList($this->globalFixtures);
        }

        if (count($fixtures)) {
            $this->stdout("\nFixtures below will be loaded:\n\n", Console::FG_YELLOW);
            $this->outputList($fixtures);
        }

        if (count($except)) {
            $this->stdout("\nFixtures that will NOT be loaded: \n\n", Console::FG_YELLOW);
            $this->outputList($except);
        }

        return $this->confirm("\nLoad above fixtures?");
    }

    /**
     * Prompts user with confirmation for fixtures that should be unloaded.
     * @param array $fixtures
     * @param array $except
     * @return boolean
     */
    private function confirmUnload($fixtures, $except)
    {
        $this->stdout("Fixtures namespace is: \n", Console::FG_YELLOW);
        $this->stdout("\t" . $this->namespace . "\n\n", Console::FG_GREEN);

        if (count($this->globalFixtures)) {
            $this->stdout("Global fixtures will be used:\n\n", Console::FG_YELLOW);
            $this->outputList($this->globalFixtures);
        }

        if (count($fixtures)) {
            $this->stdout("\nFixtures below will be unloaded:\n\n", Console::FG_YELLOW);
            $this->outputList($fixtures);
        }

        if (count($except)) {
            $this->stdout("\nFixtures that will NOT be unloaded:\n\n", Console::FG_YELLOW);
            $this->outputList($except);
        }

        return $this->confirm("\nUnload fixtures?");
    }

    /**
     * Outputs data to the console as a list.
     * @param array $data
     */
    private function outputList($data)
    {
        foreach ($data as $index => $item) {
            $this->stdout("\t" . ($index + 1) . ". {$item}\n", Console::FG_GREEN);
        }
    }

    /**
     * Checks if needed to apply all fixtures.
     * @param string $fixture
     * @return bool
     */
    public function needToApplyAll($fixture)
    {
        return $fixture == '*';
    }

    /**
     * Finds fixtures to be loaded, for example "User", if no fixtures were specified then all of them
     * will be searching by suffix "Fixture.php".
     * @param array $fixtures fixtures to be loaded
     * @return array Array of found fixtures. These may differ from input parameter as not all fixtures may exists.
     */
    private function findFixtures(array $fixtures = [])
    {
        $fixturesPath = $this->getFixturePath();

        $filesToSearch = ['*Fixture.php'];
        $findAll = ($fixtures == []);

        if (!$findAll) {

            $filesToSearch = [];

            foreach ($fixtures as $fileName) {
                $filesToSearch[] = $fileName . 'Fixture.php';
            }
        }

        $files = FileHelper::findFiles($fixturesPath, ['only' => $filesToSearch]);
        $foundFixtures = [];

        foreach ($files as $fixture) {
            $foundFixtures[] = basename($fixture, 'Fixture.php');
        }

        return $foundFixtures;
    }

    /**
     * Returns valid fixtures config that can be used to load them.
     * @param array $fixtures fixtures to configure
     * @return array
     */
    private function getFixturesConfig($fixtures)
    {
        $config = [];

        foreach ($fixtures as $fixture) {

            $isNamespaced = (strpos($fixture, '\\') !== false);
            $fullClassName = $isNamespaced ? $fixture . 'Fixture' : $this->namespace . '\\' . $fixture . 'Fixture';

            if (class_exists($fullClassName)) {
                $config[] = $fullClassName;
            }
        }

        return $config;
    }

    /**
     * Filters fixtures by splitting them in two categories: one that should be applied and not.
     * If fixture is prefixed with "-", for example "-User", that means that fixture should not be loaded,
     * if it is not prefixed it is considered as one to be loaded. Returns array:
     *
     * ~~~
     * [
     *     'apply' => [
     *         'User',
     *         ...
     *     ],
     *     'except' => [
     *         'Custom',
     *         ...
     *     ],
     * ]
     * ~~~
     * @param array $fixtures
     * @return array fixtures array with 'apply' and 'except' elements.
     */
    private function filterFixtures($fixtures)
    {
        $filtered = [
            'apply' => [],
            'except' => [],
        ];

        foreach ($fixtures as $fixture) {
            if (mb_strpos($fixture, '-') !== false) {
                $filtered['except'][] = str_replace('-', '', $fixture);
            } else {
                $filtered['apply'][] = $fixture;                
            }
        }

        return $filtered;
    }

    /**
     * Returns fixture path that determined on fixtures namespace.
     * @return string fixture path
     */
    private function getFixturePath()
    {
        return Yii::getAlias('@' . str_replace('\\', '/', $this->namespace));
    }

}
