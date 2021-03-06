<?php
namespace Controller;
use \Silex\Application,
  Project\Cache;

class Root implements \Silex\ControllerProviderInterface {
  public function connect( Application $app) {
    $index = $app['controllers_factory'];

    // $app->error(function (\Exception $e, $code) {
    //     switch ($code) {
    //         case 404:
    //             $message = 'The requested page could not be found.';
    //             break;
    //         default:
    //             $message = 'We are sorry, but something went terribly wrong.';
    //     }

    //     return new Response($message);
    // });
    
    $index->get('/', function( Application $app ) {
      $artists = Cache::get('geo.getTopArtists', 60*60*24*7, function() use ($app) {
        return $app['lastfm']->request('geo.getTopArtists', array(
          'country' => 'russia',
          'limit' => 100
        ));
      });

      return $app['twig']->render('root/index.twig', array(
        'artists' => $artists
      ));
    })->bind('index');

    $index->get('/playlists', function( Application $app ) {
      $playlists = \Model\PlaylistQuery::create()
        ->filterByUser($app['user']::get())
        ->orderByPosition()
        ->orderById('DESC')
        ->find();

      return $app['twig']->render('root/playlists.twig', array(
        'playlists' => $playlists
      ));
    })->bind('playlists');

    $index->get('/donate', function( Application $app ) {
      return $app['twig']->render('root/donate.twig', array(
      ));
    })->bind('donate');

    $index->get('/loadplaylists', function( Application $app ) {
      $playlists = \Model\PlaylistQuery::create()
        ->filterByUser($app['user']::get())
        ->orderByPosition()
        ->orderById('DESC')
        ->find();

      return $app['twig']->render('root/loadplaylists.twig', array(
        'playlists' => $playlists
      ));
    })->bind('loadplaylists');
    
    $index->post('/addplaylist', function( Application $app ) {
      if ( $app['user']::get() ) {
        $name = $app['request']->get('name');

        $playlist = new \Model\Playlist;
        $playlist->setUser($app['user']::get());
        $playlist->setName($name);
        $playlist->save();

        return $app->redirect(
          $app['url_generator']->generate('playlists')
        );
      }

      return '';
    })->bind('addplaylist');

    $index->get('/vkerror', function( Application $app ) {
      if( isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 'xmlhttprequest' == strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) ) {
          return "<div><div class='subcontent'><script>location.href = '/vkerror';</script></div></div>";
      }

      return $app['twig']->render('root/vkerror.twig', array(
      ));
    })->bind('vkerror');

    $index->post('/addtracktoplaylist', function( Application $app ) {
      if ( $app['user']::get() ) {
        $mid = $app['request']->get('mid');
        $playlistId = $app['request']->get('playlistId');

        $playlist = \Model\PlaylistQuery::create()
          ->filterByUser( $app['user']::get() )
          ->filterById( $playlistId )
          ->findOne();

        if ( $playlist ) {
          $vkTrack = Cache::get("vk_track_{$mid}", 60*60*24*14, function() use ( $app, $mid ) {
            return $app['openplayer']->audioGetById( $mid );
          });

          $playlist->setCnt($playlist->getCnt() + 1);
          $playlist->save();

          $pt = new \Model\PlaylistTrack;
          $pt->setPlaylist( $playlist );
          $pt->setTrack( serialize($vkTrack) );
          $pt->save();
        }

        return $app->redirect(
          $app['url_generator']->generate('playlists')
        );
      }

      return '';
    })->bind('addtracktoplaylist');

    $index->post('/deltrackfromplaylist', function( Application $app ) {
      if ( $app['user']::get() ) {
        $mid = $app['request']->get('mid');
        $playlistId = $app['request']->get('playlistId');

        $playlist = \Model\PlaylistQuery::create()
          ->filterByUser( $app['user']::get() )
          ->filterById( $playlistId )
          ->findOne();

        if ( $playlist ) {
          $playlist->setCnt($playlist->getCnt() - 1);
          $playlist->save();

          $ptracks = \Model\PlaylistTrackQuery::create()
            ->filterByPlaylist($playlist)
            ->find();

          foreach ( $ptracks as $track ) {
            $inf = unserialize($track->getTrack());
            if ( $mid == $inf->mid ) {
              $track->delete();
            }
          }
        }

        return $app->redirect(
          $app['url_generator']->generate('playlists')
        );
      }

      return '';
    })->bind('deltrackfromplaylist');

    $index->post('/deleteplaylist', function( Application $app ) {
      if ( $app['user']::get() ) {
        $id = $app['request']->get('id');

        $playlist = \Model\PlaylistQuery::create()
          ->filterByUser($app['user']::get())
          ->filterById($id)
          ->findOne();
        $playlist->delete();

        return $app->redirect(
          $app['url_generator']->generate('playlists')
        );
      }

      return '';
    })->bind('deleteplaylist');

    $index->post('/poschange', function( Application $app ) {
      if ( $app['user']::get() ) {
        $ids = $app['request']->get('ids');
        $ids = explode(',', $ids);

        $playlists = \Model\PlaylistQuery::create()
          ->findPKs($ids);

        foreach ( $playlists as $key => $playlist ) {
          if ( $app['user']::get('id') == $playlist->getUserId() ) {
            $playlist->setPosition(array_search($playlist->getId(), $ids));
            $playlist->save();
          }
        }

        return '';
      }
    })->bind('poschange');

    $index->get('/track/{mid}', function( Application $app, $mid ) {
      $track = Cache::get("vk_track_{$mid}", 60*60*24*14, function() use ($app, $mid) {
        return $app['openplayer']->audioGetById( $mid );
      });

      return $app['twig']->render('root/track.twig', array(
        'track' => $track,
        'i' => 0,
        'playlistId' => null,
        // 'lyrics' => $lyrics
      ));
    })->bind('track');

    $index->get('/mp3/{mid}.mp3', function( Application $app, $mid ) {
      session_write_close();

      $vkTrack = Cache::get("vk_track_{$mid}", 60*60*24, function() use ($app, $mid) {
        return $app['openplayer']->audioGetById( $mid );
      });

      $token = $app['openplayer']->getToken();
      $link = "{$vkTrack->link}?session_key={$token}";

      // If cached link is expired, recache track.
      $headers = get_headers($link);
      if ( 'HTTP/1.1 200 OK' != $headers[0] ) {
        $token = $app['openplayer']->getToken();
        $link = "{$vkTrack->link}?session_key={$token}";
      }

      header("Content-Length: {$vkTrack->size}");

      if ( $app['request']->get('dl') ) {
        header('Last-Modified:');
        header('ETag:');
        header('Content-Type: audio/mpeg');
        header('Accept-Ranges: bytes');

        header("Content-Disposition: attachment; filename=\"{$vkTrack->title}\".mp3");
        header('Content-Description: File Transfer');
        header('Content-Transfer-Encoding: binary');

        echo file_get_contents($link);
        die;
      }

      return $app->stream(function () use ($link) {
        readfile($link);
      }, 200, array('Content-Type' => 'audio/mpeg'));
    })->bind('mp3');


    $index->get('/player', function( Application $app ) {

      return $app['twig']->render('root/player.twig', array());
    })->bind('player');

    $search = function( Application $app ) {
      $artist = urldecode($app['request']->get('artist'));
      $q = urldecode($app['request']->get('q', $artist));
      $playlistId = $app['request']->get('id');

      if ( $playlistId ) {
        $playlist = \Model\PlaylistQuery::create()
          ->findOneById($playlistId);
        $pt = \Model\PlaylistTrackQuery::create()
          ->findByPlaylist($playlist);

        $tracks = array();
        foreach ( $pt as $t ) {
          $inf = unserialize($t->getTrack());
          $tracks[] = $inf;
        }
        $count = count($pt);
        $q = $playlist->getName();
      } else {
        $params = array(
          'q' => $q,
          'p' => 0,
          'count' => 200,
        );

        $search = Cache::get("vk_search_".join(',', $params), 60*60*24*7, function() use ($app, $params) {
          $search = $app['openplayer']->search($params);

          if ( $search['tracks'] ) {
            return $search;
          }

          return null;
        });

        $tracks = $search['tracks'];
        $count = $search['count'];
      }

      $artistInfo = null;
      if ( $artist ) {
        $artistInfo = Cache::get("artist.getInfo_{$artist}", 60*60*24*7, function() use ($app, $artist) {
          return $app['lastfm']->request('artist.getInfo', array(
            'artist' => $artist,
            'lang' => 'ru'
          ));
        });
      }

      return $app['twig']->render('root/search.twig', array(
        'tracks' => $tracks,
        'count' => $count,
        'artistInfo' => $artistInfo,
        'q' => $q,
        'playlistId' => $playlistId
      ));
    };
    $index->get('/search', $search)->bind('search');
    $index->get('/search/{artist}', $search)->bind('search.artist');
    $index->get('/playlist/{id}', $search)->bind('playlist');

    

    return $index;
  }


}