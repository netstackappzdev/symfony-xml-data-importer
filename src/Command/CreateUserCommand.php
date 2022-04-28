<?php
namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;

use Symfony\Component\Console\Input\InputDefinition;

// json conversion
use Symfony\Component\Config\Util\XmlUtils;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Filesystem\Filesystem;

//google sheet 
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

use League\Csv\Writer;


// the "name" and "description" arguments of AsCommand replace the
// static $defaultName and $defaultDescription properties
#[AsCommand(
    name: 'app:create-user',
    description: 'Creates a new user.',
    hidden: false,
    aliases: ['app:add-user']
)]
class CreateUserCommand extends Command
{
    protected $projectDir;
    private $logger;

    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'app:create-user';
    // the command description shown when running "php bin/console list"
    protected static $defaultDescription = 'Creates a new user.';
    public function __construct(
        bool $requirePassword = false,
        KernelInterface $kernel,
        LoggerInterface $logger
    )
    {
        // best practices recommend to call the parent constructor first and
        // then set your own properties. That wouldn't work in this case
        // because configure() needs the properties set in this constructor
        $this->requirePassword = $requirePassword;
        $this->projectDir = $kernel->getProjectDir();
        $this->logger = $logger;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // the command help shown when running the command with the "--help" option
            ->setHelp('This command allows you to Import a XML file into (CSV, JSON, Google Sheet & SQlite)')
            //->addArgument('username', InputArgument::REQUIRED, 'The username of the user.')
            //->addArgument('password', $this->requirePassword ? InputArgument::REQUIRED : InputArgument::OPTIONAL, 'User password')
            ->addOption(
                'fetch',
                null,
                InputOption::VALUE_REQUIRED,
                'Should I yell while greeting?',
                'server' // this is the new default value, instead of null
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Should I yell while greeting?',
                'JSON' // this is the new default value, instead of null
            )
            // ->setDescription('Describe args behaviors')
            // ->setDefinition(
            //     new InputDefinition([
            //         new InputArgument('arg', InputArgument::OPTIONAL),
            //         new InputOption('foo', 'f'),
            //         new InputOption('bar', 'b', InputOption::VALUE_REQUIRED),
            //         new InputOption('cat', 'c', InputOption::VALUE_OPTIONAL),
            //     ])
            // )
        ;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestArgumentValuesFor('username')) {
            // the user asks for completion input for the "names" option

            // the value the user already typed, e.g. when typing "app:greet Fa" before
            // pressing Tab, this will contain "Fa"
            $currentValue = $input->getCompletionValue();

            // get the list of username names from somewhere (e.g. the database)
            // you may use $currentValue to filter down the names
            $availableUsernames = ['local','server'];

            // then add the retrieved names as suggested values
            $suggestions->suggestValues($availableUsernames);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {       
        $fetch =  $input->getOption('fetch');
        $to = $input->getOption('to');

        //read file from public path
        $webPath = $this->projectDir. '/public/'; 
        $xml =  $webPath.'employee.xml';
        $output->writeln('reading XML file...');
        $XMLdata = $this->readXMLFile($xml);
        $output->writeln("XML converted into JSON data");

        // fetch the keys of the first json object
        $headers = array_keys(current($XMLdata));

        // flatten the json objects to keep only the values as arrays
        $formattedData = [];
        foreach ($XMLdata as $jsonObject) {
            $jsonObject = array_map('strval', $jsonObject);
            $formattedData[] = array_values($jsonObject);
        }
        $this->saveCsvToFile($headers,$formattedData, $output);

        $jsonData = json_encode($XMLdata);
        $this->saveJsonToFile($jsonData, $output);

        //import the data into Google Spreadsheet
        $this->sendDataToGoogleSheet($headers,$formattedData, $output);

        //import the data into SQLlite
        $this->sqlLiteImport();

    
        // the value returned by someMethod() can be an iterator (https://secure.php.net/iterator)
        // that generates and returns the messages with the 'yield' PHP keyword
        //$output->writeln($this->someMethod());
    
        // outputs a message followed by a "\n"
        $output->writeln('Whoa!');
        // retrieve the argument value using getArgument()
        //$output->writeln('Username: '.$input->getArgument('username'));
    
        return Command::SUCCESS;
    }   

    private function saveCsvToFile($headers,$rows,$output){
        try {
            $file = $this->projectDir.'/public/file.csv';
            // insert the headers and the rows in the CSV file
            $csv = Writer::createFromPath($file, 'w');
            $csv->insertOne($headers);
            $csv->insertAll($rows);
        }
        catch(IOException $e) {
            //log code here
        }
    }

    private function sqlLiteImport(){
        $dbRoute = $this->projectDir . '/productsup.db';
        try {            
            $webPath = $this->projectDir. '/public/'; 
            $db = new \PDO("sqlite:".$webPath ."/test.db");            
            $file = $this->projectDir.'/public/file.csv';
            $this->import_csv_to_sqlite($db,$file, $options=array());
        } catch (\Exception $e) {
            echo $e->getMessage();
        }
    }

    private function import_csv_to_sqlite(&$pdo, $csv_path, $options = array())
    {
        extract($options);
        
        if (($csv_handle = fopen($csv_path, "r")) === FALSE)
            throw new Exception('Cannot open CSV file');
            
        if(empty($delimiter))
            $delimiter = ',';
            
        if(empty($table))
            $table = preg_replace("/[^A-Z0-9]/i", '', basename($csv_path));
        
        if(empty($fields)){
            $fields = array_map(function ($field){
                return strtolower(preg_replace("/[^A-Z0-9]/i", '', $field));
            }, fgetcsv($csv_handle, 0, $delimiter));
        }
        $create_fields_str = join(', ', array_map(function ($field){
            return "$field TEXT NULL";
        }, $fields));
        
        $pdo->beginTransaction();
        
        $create_table_sql = "CREATE TABLE IF NOT EXISTS $table ($create_fields_str)";
        $pdo->exec($create_table_sql);

        $insert_fields_str = join(', ', $fields);
        $insert_values_str = join(', ', array_fill(0, count($fields),  '?'));
        $insert_sql = "INSERT INTO $table ($insert_fields_str) VALUES ($insert_values_str)";
        $insert_sth = $pdo->prepare($insert_sql);
        
        $inserted_rows = 0;
        while (($data = fgetcsv($csv_handle, 0, $delimiter)) !== FALSE) {
            $insert_sth->execute($data);
            $inserted_rows++;
        }
        try {
        
            $pdo->commit();

        } catch (\Exception $e) {
            echo $e->getMessage();
        }
        
        fclose($csv_handle);
        
        return array(
                'table' => $table,
                'fields' => $fields,
                'insert' => $insert_sth,
                'inserted_rows' => $inserted_rows
            );

    }

    private function readXMLFile($xml){
        try {
            $dom = XmlUtils::loadFile($xml);
            $city = $dom->getElementsByTagName('root')
                ->item(0);
            //dump the xml data into array
            // dump(
            //     XmlUtils::convertDomElementToArray($city)
            // );
            $XMLArray = XmlUtils::convertDomElementToArray($city);
            return $XMLArray['row'];
        }catch(Exception $e) {
            echo 'Message: ' .$e->getMessage();
        }
    }

    private function saveJsonToFile($jsonData,$output){
        //write the json file into system
        $filesystem = new Filesystem();
        try {
            $file = $this->projectDir.'/public/file.json';
            $filesystem->dumpFile($file, $jsonData); 
            $output->writeln("file saved into $file");
        }
        catch(IOException $e) {
            //log code here
        }
    }

    private function sendDataToGoogleSheet($headers,$XMLdata, $output){
        // Get the API client and construct the service object.
        $client = $this->getClient();
        $service = new \Google_Service_Sheets($client);

        // TODO: Assign values to desired properties of `requestBody`:
        $spreadsheet = new \Google_Service_Sheets_Spreadsheet([
            'properties' => [
                'title' => 'products up interview test'
            ]
        ]);
        $spreadsheet = $service->spreadsheets->create($spreadsheet, [
            'fields' => 'spreadsheetId'
        ]);
        //printf("Spreadsheet ID: %s\n", $spreadsheet->spreadsheetId);

        $output->writeln("Google Sheet created , Spreadsheet ID: ". $spreadsheet->spreadsheetId);

        // The spreadsheet to apply the updates to.
        // $spreadsheetId = 'my-spreadsheet-id';  // TODO: Update placeholder value.

        // // TODO: Assign values to desired properties of `requestBody`:
        // $requestBody = new \Google_Service_Sheets_BatchUpdateSpreadsheetRequest();

        // $response = $service->spreadsheets->batchUpdate($spreadsheet->spreadsheetId, $requestBody);

        // Create the value range Object
        $service = new \Google_Service_Sheets($client);
        $range = 'Sheet1!A:Z';
        // $sheetTitle = array_flip($XMLArray['row'][0]);
        // print_r($sheetTitle); die;
        // $formattedArray = array_values($XMLArray['row'][0]);
        // $formattedArray = array_map('strval',$formattedArray);
        // echo  $formattedArray = json_encode($formattedArray);
        // Remove all the "null" values from rows
        /*$formattedArray = $XMLdata['row'];
        $sheetTittle = array();
        foreach ($formattedArray[0] as $key => $value) {
            $sheetTittle[0][] = $key;
        }
        foreach ($formattedArray as $key => $value) {
            foreach($formattedArray[$key] as $key2 => $value2) {
                if(is_null($value2))
                    $formattedArray[$key][$key2] = "";
            }
            $formattedArray[$key] = array_values($formattedArray[$key]);
        }*/
        $sheetTittle = array();
        $sheetTittle[] = $headers;
        $outputArray = array_merge($sheetTittle, $XMLdata);
        $body = new \Google_Service_Sheets_ValueRange([
            'values' =>$outputArray
        ]);
        $params = [
            'valueInputOption' => "USER_ENTERED",
            'insertDataOption' => "INSERT_ROWS"
        ];
        $result = $service->spreadsheets_values->append($spreadsheet->spreadsheetId, $range, $body, $params);
        $output->writeln("XML Data updated on google sheet - $spreadsheet->spreadsheetId");
    }

    /**
     * Returns an authorized API client.
     * @return Google_Client the authorized client object
     */
    private function getClient()
    {
        
        $credentialsFilePath = $this->projectDir. '/config/google/client_secret_348365894735-1kb5idgb6dur3tmb90u0shlegrljo18j.apps.googleusercontent.com.json'; 

        $client = new \Google_Client();
        $client->setApplicationName('Google Sheets API PHP Quickstart');
        $client->setScopes(\Google_Service_Sheets::SPREADSHEETS);
        $client->setAuthConfig($credentialsFilePath);
        $client->setAccessType('offline');
        $client->setPrompt('select_account consent');
        $client->setRedirectUri('http://localhost:8080/productsup/my_project/public/oauth2callback.php');


        // Load previously authorized token from a file, if it exists.
        // The file token.json stores the user's access and refresh tokens, and is
        // created automatically when the authorization flow completes for the first
        // time.
        $tokenPath = $this->projectDir.'/config/google/token.json';
        if (file_exists($tokenPath)) {
            $accessToken = json_decode(file_get_contents($tokenPath), true);
            $client->setAccessToken($accessToken);
        }

        // If there is no previous token or it's expired.
        if ($client->isAccessTokenExpired()) {
            // Refresh the token if possible, else fetch a new one.
            if ($client->getRefreshToken()) {
                $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
            } else {
                // Request authorization from the user.
                $authUrl = $client->createAuthUrl();
                printf("Open the following link in your browser:\n%s\n", $authUrl);
                print 'Enter verification code: ';
                $authCode = trim(fgets(STDIN));

                // Exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                $client->setAccessToken($accessToken);

                // Check to see if there was an error.
                if (array_key_exists('error', $accessToken)) {
                    throw new Exception(join(', ', $accessToken));
                }
            }
            // Save the token to a file.
            if (!file_exists(dirname($tokenPath))) {
                mkdir(dirname($tokenPath), 0700, true);
            }
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        }
        return $client;
    }
}