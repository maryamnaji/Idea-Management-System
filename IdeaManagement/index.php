<?php

session_start();
require_once 'vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

DB::$user = 'ideamanagement';
DB::$dbName = 'ideamanagement';
DB::$password = 'aL4CQnJGyEn2r0XW';
DB::$port = 3306;
DB::$host = 'localhost';
DB::$encoding = 'utf8';

// Slim creation and setup
$app = new \Slim\Slim(array(
    'view' => new \Slim\Views\Twig()
        ));

$view = $app->view();
$view->parserOptions = array(
    'debug' => true,
    'cache' => dirname(__FILE__) . '/cache'
);
$view->parserExtensions = array(
    new \Slim\Views\TwigExtension(),
);
$view->setTemplatesDirectory(dirname(__FILE__) . '/templates');


// create a log channel
$log = new Logger('main');
$log->pushHandler(new StreamHandler('logs/everything.log', Logger::DEBUG));
$log->pushHandler(new StreamHandler('logs/errors.log', Logger::ERROR));

//Root (Home page)
$app->get('/', function() use ($app) {
    $app->render('index.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL
    ));
})->name("root");


//Display register page
$app->get('/register', function() use ($app) {
    $app->render('register.html.twig');
});

//Submit register form
$app->post('/register', function() use ($app) {
    $displayname = $app->request()->post('displayname');
    $email = $app->request()->post('email');
    $password = $app->request()->post('password');

    DB::insert('users', array(
        'displayname' => $displayname,
        'email' => $email,
        'password' => password_hash($password, CRYPT_BLOWFISH)
    ));

    $app->render('index.html.twig');
});

//Display login page
$app->get('/login', function() use ($app) {
    $app->render('login.html.twig');
})->name("login");

//Submit login form
$app->post('/login', function() use ($app, $log) {
    $email = $app->request()->post('email');
    $password = $app->request()->post('password');
    $passwordConfirm = $app->request()->post('password');

    $user = DB::queryFirstRow("SELECT * FROM users WHERE email=%s", $email);
    if (!$user) {
        $log->debug(sprintf("User failed for email %s from IP %s", $email, $_SERVER['REMOTE_ADDR']));
        $app->render('login.html.twig');
    } else {
        if (password_verify($password, $user['password'])) {
            unset($user['password']);
            $_SESSION['user'] = $user;

            $log->debug(sprintf("User %s logged in successfuly from IP %s", $user['id'], $_SERVER['REMOTE_ADDR']));
            $app->render('login_success.html.twig', array(
                'user' => $_SESSION['user']
            ));
        } else {
            $log->debug(sprintf("User failed for email %s from IP %s", $email, $_SERVER['REMOTE_ADDR']));
            $app->render('login.html.twig');
        }
    }
});

//Logout and destroy session
$app->get('/logout', function() use ($app) {
    unset($_SESSION['user']);
    $app->render('logout_success.html.twig', array(
        'user' => NULL
    ));
});

//Display idealist
$app->get('/ideas', function() use ($app) {
    $ideas = DB::query("SELECT i.id as ideaid,i.title,i.summary,i.submissiondate,u.displayname,lat,lon,imagepath FROM ideas as i join users as u on i.authorid=u.id order by i.submissiondate desc");
    $app->render('ideas.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
        'ideas' => $ideas
    ));
})->name("ideas");

$app->get('/search', function() use ($app) {
    $ideas = DB::query("SELECT i.id as ideaid,i.title,i.summary,i.submissiondate,u.displayname,lat,lon,imagepath FROM ideas as i join users as u on i.authorid=u.id order by i.submissiondate desc");
    $app->render('ideas.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
        'ideas' => $ideas
    ));
});

//Display idea details 
$app->get('/ideas/:id', function($id) use ($app) {
    $idea = DB::queryFirstRow("SELECT * FROM ideas as i join users as u on i.authorid=u.id where i.id=%s", $id);
    $comments = DB::query("SELECT * FROM comments as c join users as u on c.authorid=u.id where c.ideaid=%s order by c.creationtimestamp desc", $id);
    $app->render('ideadetail.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
        'idea' => $idea,
        'comments' => $comments
    ));
});

//Submit comment form
$app->post('/ideas/:id', function($id) use ($app) {
    $message = $app->request()->post('message');

    DB::insert('comments', array(
        'authorid' => $_SESSION['user']['id'],
        'message' => $message,
        'ideaid' => $id
    ));
    $app->render('submitcomment_success.html.twig', array(
        'id' => $id
    ));
});


//Search on ideas
$app->post('/search', function() use ($app) {
    $search = $app->request()->post('search');
    $ideas = DB::query("SELECT i.id as ideaid,i.title,i.summary,i.submissiondate,u.displayname,lat,lon,imagepath FROM ideas as i join users as u on i.authorid=u.id  where i.title like %ss order by i.submissiondate desc ", $search);

    $app->render('ideas.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
        'ideas' => $ideas,
        'searchTerm' => $search
    ));
});

//Display idea submission
$app->get('/ideasubmission', function() use ($app) {
    if (isset($_SESSION['user'])) {
        $app->render('ideasubmission.html.twig', array(
            'user' => $_SESSION['user']
        ));
    } else {
        $app->render('warning.html.twig');
    }
});

//Submit idea submission form
$app->post('/ideasubmission', function() use ($app, $log) {
    $title = $app->request()->post('title');
    $summary = $app->request()->post('summary');
    $lat = $app->request()->post('lat');
    $lon = $app->request()->post('lon');

    $image = $_FILES['image'];
    if ($_FILES['image']['name'] != '') {
        $imageInfo = getimagesize($image['tmp_name']);
        if (!$imageInfo) {
            $log->debug("File does not look like a valid image");
        } else {
            // never allow '..' in the file name
            if (strstr($image['name'], '..')) {
                $log->debug("File name invalid");
            }
            // only allow select extensions
            $ext = strtolower(pathinfo($image['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, array('jpg', 'jpeg', 'gif', 'png'))) {
                $log->debug("File extension invalid");
            }
            // do not allow to override an existing file
            if (file_exists('uploads/' . $image['name'])) {
                $log->debug("File name already exists, refusing to override.");
            }
        }

        move_uploaded_file($image['tmp_name'], 'uploads/' . $image['name']);
    }
    DB::insert('ideas', array(
        'title' => $title,
        'summary' => $summary,
        'authorid' => $_SESSION['user']['id'],
        'lat' => $lat,
        'lon' => $lon,
        'imagepath' => $image['name']
    ));
    $app->render('ideasubmission_success.html.twig',array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
    ));
  
});

//Display challenges
$app->get('/challenges', function() use ($app) {   
    $app->render('challenges.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
    ));
});

//Display chart
$app->get('/chart', function() use ($app) { 
    $ideasBymonth = DB::query("SELECT count(*) as ideacount FROM ideas GROUP BY month(submissiondate) order by month(submissiondate)");
    $app->render('chart.html.twig', array(
         'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL,
        'ideasBymonth'=>$ideasBymonth
    ));
});


//Display about us
$app->get('/aboutus', function() use ($app) {
    $app->render('aboutus.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL
    ));
});

//Display contact us
$app->get('/contactus', function() use ($app) {
    $app->render('contactus.html.twig', array(
        'user' => isset($_SESSION['user']) ? $_SESSION['user'] : NULL
    ));
});



$app->run();
