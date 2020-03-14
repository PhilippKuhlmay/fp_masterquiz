<?php
namespace Fixpunkt\FpMasterquiz\Controller;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/***
 *
 * This file is part of the "Master-Quiz" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 *  (c) 2019 Kurt Gusbeth <k.gusbeth@fixpunkt.com>, fixpunkt werbeagentur gmbh
 *
 ***/

/**
 * QuizController
 */
class QuizController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * quizRepository
     *
     * @var \Fixpunkt\FpMasterquiz\Domain\Repository\QuizRepository
     * @inject
     */
    protected $quizRepository = null;

    /**
     * answerRepository
     *
     * @var \Fixpunkt\FpMasterquiz\Domain\Repository\AnswerRepository
     * @inject
     */
    protected $answerRepository = null;

    /**
     * participantRepository
     *
     * @var \Fixpunkt\FpMasterquiz\Domain\Repository\ParticipantRepository
     * @inject
     */
    protected $participantRepository = null;
    
    /**
     * selectedRepository
     *
     * @var \Fixpunkt\FpMasterquiz\Domain\Repository\SelectedRepository
     * @inject
     */
    protected $selectedRepository = null;
    
    /**
     * participant
     *
     * @var \Fixpunkt\FpMasterquiz\Domain\Model\Participant
     */
    protected $participant = null;
    
    /**
     * @var \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface
     */
    protected $configurationManager;
    
    /**
     * Injects the Configuration Manager and is initializing the framework settings: wird doppelt aufgerufen!
     *
     * @param \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface $configurationManager Instance of the Configuration Manager
     */
    public function injectConfigurationManager(\TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface $configurationManager) {
            $this->configurationManager = $configurationManager;
            /*$tsSettings = $this->configurationManager->getConfiguration(
                \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FRAMEWORK,
                'fp_masterquiz',
                'fpmasterquiz_pi1'
            );*/
            $tsSettings = $this->configurationManager->getConfiguration(
                \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
            );
            $tsSettings = $tsSettings['plugin.']['tx_fpmasterquiz.']['settings.'];
            $originalSettings = $this->configurationManager->getConfiguration(
                \TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS
            );
            // if flexform setting is empty and value is available in TS
            $overrideFlexformFields = GeneralUtility::trimExplode(',', $tsSettings['overrideFlexformSettingsIfEmpty'], true);
            foreach ($overrideFlexformFields as $fieldName) {
                if (strpos($fieldName, '.') !== false) {
                    // Multilevel
                    $keyAsArray = explode('.', $fieldName);
                    if (!($originalSettings[$keyAsArray[0]][$keyAsArray[1]]) && isset($tsSettings[$keyAsArray[0] . '.'][$keyAsArray[1]])) {
                        //echo $keyAsArray[0].'.'.$keyAsArray[1] .': #'.$originalSettings[$keyAsArray[0]][$keyAsArray[1]] . '#' .$tsSettings[$keyAsArray[0] . '.'][$keyAsArray[1]].'#';
                        $originalSettings[$keyAsArray[0]][$keyAsArray[1]] = $tsSettings[$keyAsArray[0] . '.'][$keyAsArray[1]];
                    }
                } else {
                    // Simple
                    if (!($originalSettings[$fieldName]) && isset($tsSettings[$fieldName])) {
                        //echo $fieldName .': #'.$originalSettings[$fieldName] .'#'. $tsSettings[$fieldName].'#';
                        $originalSettings[$fieldName] = $tsSettings[$fieldName];
                    }
                }
            }
            $this->settings = $originalSettings;
    }
    
    /**
     * action doAll
     *
     * @param \Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz
     * @return array
     */
    public function doAll(\Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz) {
    	/* @var \Fixpunkt\FpMasterquiz\Domain\Model\Answer $answer */
        $answer = Null;
        $saveIt = FALSE;
    	$newUser = FALSE;
    	$reload = FALSE;
    	$partBySes = NULL;
    	$pages = 0;
    	$questions = 0;
    	$maximum1 = 0;
    	$finalContent = '';
    	$debug = '';
    	$quizUid = $quiz->getUid();
    	$questionsPerPage = intval($this->settings['pagebrowser']['itemsPerPage']);
    	$showAnswers = $this->request->hasArgument('showAnswers') ? intval($this->request->getArgument('showAnswers')) : 0;
    	if ($this->request->hasArgument('session')) {
    		$session = $this->request->getArgument('session');
    	} else if (!$this->request->hasArgument('participant')) {
    		// keine Session gefunden... und jetzt Cookie checken?
    		if (intval($this->settings['user']['useCookie']) == -1) {
    			$session = $GLOBALS["TSFE"]->fe_user->getKey('ses', 'qsession' . $quizUid);
    		} else if ((intval($this->settings['user']['useCookie']) > 0) && isset($_COOKIE['qsession' . $quizUid])) {
    			$session = $_COOKIE['qsession' . $quizUid];
    		}
    		if ($session) {
    			$this->participant = $this->participantRepository->findOneBySession($session);
    			if ($this->settings['debug'])
	    			$debug .= "\nsession from cookie: " . $session . '; and the participant-uid of that session: ' . $this->participant->getUid();
    		} else {
    			if ($this->settings['user']['checkFEuser']) {
    				$this->participant = $this->participantRepository->findOneByUserAndQuiz(intval($GLOBALS['TSFE']->fe_user->user['uid']), $quizUid);
    				if ($this->participant) {
    					$session = $this->participant->getSession();
    					if ($this->settings['debug'])
    						$debug .= "\nsession from FEuser: " . $session . '; and the participant-uid: ' . $this->participant->getUid();
    				}
    			}
    			if (!$session) {
    				$session = uniqid( mt_rand(1000,9999) );
    				$newUser = TRUE;
    				$this->participant = NULL;
    				if ($this->settings['debug']) $debug .= "\ncreating new session: " . $session;
    			}
    		}
    	}
    	if (intval($this->settings['user']['useCookie']) == -1) {
    		// Store the session in a cookie
    		$GLOBALS['TSFE']->fe_user->setKey('ses', 'qsession' . $quizUid, $session);
    		$GLOBALS["TSFE"]->storeSessionData();
    	} else if (intval($this->settings['user']['useCookie']) > 0) {
    		setcookie('qsession' . $quizUid, $session, time()+(3600*24*intval($this->settings['user']['useCookie'])));  /* verfällt in x Tagen */
    	}
    	
    	if ($this->request->hasArgument('participant') && $this->request->getArgument('participant')) {
    		// wir sind nicht auf Seite 1
    		$participantUid = intval($this->request->getArgument('participant'));
    		$this->participant = $this->participantRepository->findOneByUid($participantUid);
    		$session = $this->participant->getSession();
    		if ($this->settings['debug']) $debug .= "\nparticipant from request: " . $participantUid;
    	} else {
    		if (!$this->participant) {
    			$this->participant = GeneralUtility::makeInstance('Fixpunkt\\FpMasterquiz\\Domain\\Model\\Participant');
    			if ($this->settings['debug']) $debug .= "\nmaking new participant.";
    		}
    	}
    	$page = $this->request->hasArgument('@widget_0') ? $this->request->getArgument('@widget_0') : 1;
    	if (is_array($page)) {
    		$page = intval($page['currentPage']);
    	} else {
    		$page = $this->request->hasArgument('currentPage') ? intval($this->request->getArgument('currentPage')) : 1;
    	}
    	$reachedPage = $this->participant->getPage();
    	if ($reachedPage >= $page) {
    		// beantwortete Seiten soll man nicht nochmal beantworten können
    		$showAnswers = TRUE;
    	}
    	if (!$questionsPerPage) {
    		$questionsPerPage = 1;
    	}
    	$showAnswerPage = intval($this->settings['showAnswerPage']);
    	if ($showAnswerPage && !$showAnswers) {
    		// als nächstes erstmal die Antworten dieser Seite zeigen
    		$nextPage = $page;
    	} else {
    		$nextPage = $page + 1;
    	}
    	if ($showAnswers) {
    		$lastPage = $page;
    	} else {
    		$lastPage = $page -1;
    	}
    	$questions = count($quiz->getQuestions());
    	if ($showAnswers || (!$showAnswerPage && $page > 1)) {
    		// Antworten sollen ausgewertet und gespeichert werden
    		if ($reachedPage < $page) {
    			// nur nicht beantwortete Seiten speichern
    			$saveIt = TRUE;
    		}
    		$persistenceManager = $this->objectManager->get('TYPO3\\CMS\\Extbase\\Persistence\\Generic\\PersistenceManager');
    		if (!$this->participant->getUid()) {
    			if (!$newUser) {
    				$partBySes = $this->participantRepository->findOneBySession($session);
    			}
    			if ($partBySes) {
    				$this->participant = $partBySes;
    				$reload = TRUE;
    				if ($this->settings['debug']) $debug .= "\nReload nach absenden von Seite 1 detektiert.";
    			} else {
	    			$defaultName = $this->settings['user']['defaultName'];
	    			$defaultName = str_replace('{TIME}', date('Y-m-d H:i:s'), $defaultName);
	    			$defaultEmail = $this->settings['user']['defaultEmail'];
	    			$defaultHomepage = $this->settings['user']['defaultHomepage'];
	    			if ($this->settings['user']['askForData']) {
	    			    if ($this->request->hasArgument('name') && $this->request->getArgument('name')) {
	    			        $defaultName = $this->request->getArgument('name');
	        			}
	        			if ($this->request->hasArgument('email') && $this->request->getArgument('email')) {
	        			    $defaultEmail = $this->request->getArgument('email');
	        			}
	        			if ($this->request->hasArgument('homepage') && $this->request->getArgument('homepage')) {
	        			    $defaultHomepage = $this->request->getArgument('homepage');
	        			}
	    			}
	    			$this->participant->setName($defaultName);
	    			$this->participant->setEmail($defaultEmail);
	    			$this->participant->setHomepage($defaultHomepage);
	    			$this->participant->setUser(intval($GLOBALS['TSFE']->fe_user->user['uid']));
	    			$this->participant->setIp($this->getRealIpAddr());
	    			$this->participant->setSession($session);
	    			$this->participant->setQuiz($quiz);
	    			$this->participant->setMaximum2($quiz->getMaximum2());
	    			$this->participantRepository->add($this->participant);
	    			$persistenceManager->persistAll();
	    			$newUser = TRUE;
	    			if ($this->settings['debug']) {
	    				$debug .= "\nNew participant created: " . $this->participant->getUid();
    				}
    			}
    		}
    	}
    	if ($saveIt && !$reload) {
    		// cycle through all questions after a submit
    		foreach ($quiz->getQuestions() as $question) {
    			$quid = $question->getUid();
    			$debug .= "\n#" . $quid . '#: ';
    			if ($this->request->hasArgument('quest_' . $quid) && $this->request->getArgument('quest_' . $quid)) {
    				$isActive = TRUE;
    			} else if ($_POST['quest_' . $quid]) {
    				// Ajax-call is without extensionname :-(
    				$isActive = TRUE;
    			} else {
    				$isActive = FALSE;
    			}
    			if ($isActive) {	
    				// Auswertung der abgesendeten Fragen
    				if (!$newUser) {
    					// zuerst prüfen, ob dieser Eintrag schon existiert (z.B. durch Reload)
    					$vorhanden = $this->selectedRepository->countByParticipantAndQuestion($this->participant->getUid(), $quid);
    				}
    				if ($vorhanden > 0) {
    					$debug .= ' reload! ';
    				} else {
	    				$debug .= ' OK ';
	    				// selected/answered question
	    				$selected = GeneralUtility::makeInstance('Fixpunkt\\FpMasterquiz\\Domain\\Model\\Selected');
	    				$selected->setQuestion($question);
	    				$qmode = $question->getQmode();
	    				switch ($qmode) {
	    					case 0:
	    					    // Checkbox
	    						foreach ($question->getAnswers() as $answer) {
	    							$auid = $answer->getUid();
	    							if ($this->request->hasArgument('answer_' . $quid . '_' . $auid) && $this->request->getArgument('answer_' . $quid . '_' . $auid)) {
	    								$selectedAnswerUid = intval($this->request->getArgument('answer_' . $quid . '_' . $auid));
	    								$debug .= $quid . '_' . $auid . '-' . $selectedAnswerUid . ' ';
	    							} else if ($_POST['answer_' . $quid . '_' . $auid]) {
	    								$selectedAnswerUid = intval($_POST['answer_' . $quid . '_' . $auid]);
	    								$debug .= $quid . '_' . $auid . '-' . $selectedAnswerUid . ' ';
	    							} else {
	    								$selectedAnswerUid = 0;
	    							}
	    							if ($selectedAnswerUid) {
	    								$selectedAnswer = $this->answerRepository->findByUid($selectedAnswerUid);
	    								$selected->addAnswer($selectedAnswer);
	    								$newPoints = $selectedAnswer->getPoints();
	    								if ($newPoints != 0) {
	    								    $selected->addPoints($newPoints);
		    								$this->participant->addPoints($newPoints);
		    								$debug .= '+' .$newPoints . 'P ';
		    							}
	    							}
	    							$maximum1 += $answer->getPoints();
	    						}
	    						break;
	    					case 1:
	    					case 2:
							case 7:
	    					    // Radio-box und Select-option
	    						if ($this->request->hasArgument('answer_' . $quid) && $this->request->getArgument('answer_' . $quid)) {
	    							$selectedAnswerUid = intval($this->request->getArgument('answer_' . $quid));
	    							$debug .= $quid . '-' . $selectedAnswerUid . ' ';
	    						} else if ($_POST['answer_' . $quid]) {
	    							$selectedAnswerUid = intval($_POST['answer_' . $quid]);
	    							$debug .= $quid . '-' . $selectedAnswerUid . ' ';
	    						} else {
	    							$selectedAnswerUid = 0;
	    						}
	    						if ($selectedAnswerUid) {
	    							$selectedAnswer = $this->answerRepository->findByUid($selectedAnswerUid);
	    							$selected->addAnswer($selectedAnswer);
	    							if ($qmode == 7) {
	    								$cycle = count($question->getAnswers());
	    								foreach ($question->getAnswers() as $answer) {
	    									if ($answer->getUid() == $selectedAnswerUid) {
	    										$newPoints = $cycle;
	    										break;
	    									}
	    									$cycle--;
	    								}
	    							} else {
	    								$newPoints = $selectedAnswer->getPoints();
	    							}
	    							if ($newPoints != 0) {
	    							    $selected->addPoints($newPoints);
	    							    $this->participant->addPoints($newPoints);
	    							    $debug .= '+' .$newPoints . 'P ';
	    							}
	    						}
	    						$maximum1 += $question->getMaximum1();
	    						break;
                            case 3:
                            case 5:
                                // When enter an answer in a textbox: try to evaluate the answer of the textbox
	    					    $this->evaluateInputTextAnswerResult($quid, $question, $selected, $debug, $maximum1);
	    					    break;
	    				}
	    				// assign the selected dataset to the participant
	    				$this->participant->addSelection($selected);
    				}
    			}
    		}
    		// Update the participant result
    		if ($maximum1 > 0) {
    			$this->participant->addMaximum1($maximum1);
    		}
    		$this->participant->setPage($lastPage);
    		$this->participantRepository->update($this->participant);
    		$persistenceManager->persistAll();
    	}
    	$pages = intval(ceil($questions / $questionsPerPage));
    	if ($this->settings['debug']) {
    		$debug .= "\nlast page: ".$lastPage.'; page: '.$page.'; reached page before: '.$reachedPage.'; next page: '.$nextPage.'; showAnsers: '.$showAnswers;
    		$debug .= "\nqs/qpp=" . $questions . '/' . $questionsPerPage . '=' . $pages;
    	}
    	$showAnswersNext = 0;
    	if ($page > $pages) {
    		// finale Auswertung ...
    		$final = 1;
    		foreach ($quiz->getEvaluations() as $evaluation) {
    			if (!$evaluation->isEvaluate()) {
    				// Punkte auswerten
    				$final_points = $this->participant->getPoints();
    			} else {
    				// Prozente auswerten
    				$final_points = $this->participant->getPercent2();
    			}
    			if (($final_points >= $evaluation->getMinimum()) && ($final_points <= $evaluation->getMaximum())) {
    				// Punkte-Match
    				if ($evaluation->getPage() > 0) {
    					// Weiterleitung zu diese Seite
    					$this->redirectToURI(
    							$this->uriBuilder->reset()
    							->setTargetPageUid($evaluation->getPage())
    							->build()
    					);
    				} else if ($evaluation->getCe() > 0) {
    					// Content-Element ausgeben
    					// oder so: https://www.andrerinas.de/tutorials/typo3-viewhelper-zum-rendern-von-tt-content-anhand-der-uid.html
    					$ttContentConfig = array(
    							'tables'       => 'tt_content',
    							'source'       => $evaluation->getCe(),
    							'dontCheckPid' => 1);
    					$finalContent = $this->objectManager->get('TYPO3\CMS\Frontend\ContentObject\RecordsContentObject')->render($ttContentConfig);
    				}
    			}
    		}
    		// Alle Ergebnisse nicht nur das eigene anzeigen
    		if ($this->settings['showAllAnswers'] == 1) {
    		    $allResults = [];
    		    $ownResults = [];
    		    $selectedRepository = $this->objectManager->get('Fixpunkt\\FpMasterquiz\\Domain\\Repository\\SelectedRepository');
    		    // alle Fragen durchgehen, die der User beantwortet hat:
    		    foreach ($this->participant->getSelections() as $selection) {
    		        $oneQuestion = $selection->getQuestion();
    		        $questionID = $oneQuestion->getUid();
    		        $allResults[$questionID] = [];
    		        $ownResults[$questionID] = [];
    		        // User-Antworten einer bestimmten Frage:
    		        $allAnsweredQuestions = $selectedRepository->findByQuestion($questionID);
    		        // alle Ergebnisse durchgehen:
    		        foreach ($allAnsweredQuestions as $allAnswers) {
    		            // alle Antworten auf diese Frage:
    		            foreach ($allAnswers->getAnswers() as $oneAnswer) {
    		                if ($this->settings['debug']) {
    		                  $debug .= "\n all:" . $oneAnswer->getTitle() . ': ' . $oneAnswer->getPoints() . "P";
    		                }
    		                $allResults[$questionID][$oneAnswer->getUid()]++;
    		            }
    		        }
    		        // eigene Ergebnisse durchgehen
    		        foreach ($selection->getAnswers() as $oneAnswer) {
    		          if ($this->settings['debug']) {
    		             $debug .= "\n own:" . $oneAnswer->getTitle() . ': ' . $oneAnswer->getPoints() . "P";
    		          }
    		          $ownResults[$questionID][$oneAnswer->getUid()]++;
    		        }
    		        // gesammeltes speichern bei: alle möglichen Antworten einer Frage
    		        foreach ($oneQuestion->getAnswers() as $oneAnswer) {
    		            $oneAnswer->setAllAnswers(intval($allResults[$questionID][$oneAnswer->getUid()]));
    		            $oneAnswer->setOwnAnswer (intval($ownResults[$questionID][$oneAnswer->getUid()]));
    		        }
    		    }
    		}
    		if ($this->settings['email']['sendToAdmin'] || $this->settings['email']['sendToUser']) {
    			// GGf. Emails versenden
    			// TODO
    		}
    	} else {
    		$final = 0;
    		// toggle mode for show answers after submit questions
    		if ($showAnswerPage) {
    			$showAnswersNext = $showAnswers == 1 ? 0 : 1;
    		}
    	}
    	$data = [
   			'page' => $page,
   			'pages' => $pages,
   			'nextPage' => $nextPage,
   			'questions' => $questions,
   			'final' => $final,
   			'finalContent' => $finalContent,
   			'showAnswers' => $showAnswers,
   			'showAnswersNext' => $showAnswersNext,
    		'session' => $session,
   			'debug' => $debug
    	];
    	return $data;
    }
    
    /**
     * Try to evaluate the answer of an Input Textbox 
     * 
     * @param int $i_quid The Question ID
     * @param \Fixpunkt\FpMasterquiz\Domain\Model\Question $i_question The Question dataset
     * @param \Fixpunkt\FpMasterquiz\Domain\Model\Selected $c_selected The Selected dataset
     * @param string $c_debug Debug
     * @param int $c_maximum1 The max. possible points until the current question
     */
    protected function evaluateInputTextAnswerResult(int $i_quid, 
                                                   \Fixpunkt\FpMasterquiz\Domain\Model\Question $i_question, 
                                                   \Fixpunkt\FpMasterquiz\Domain\Model\Selected &$c_selected,
                                                   string &$c_debug,
                                                   int &$c_maximum1){
        // retreive answer over the GET arguments
        if ($this->request->hasArgument('answer_text_' . $i_quid) && $this->request->getArgument('answer_text_' . $i_quid)) {
            $answerText = $this->request->getArgument('answer_text_' . $i_quid);
            $c_debug .= "\n" . $i_quid . '- Answer in the Inputbox is: ' . $answerText . ' ';
            
        // retreive answer over the POST arguments
        } else if ($_POST['answer_text_' . $i_quid]) {
            $answerText = $_POST['answer_text_' . $i_quid];
            $c_debug .= "\n" . $i_quid . '- Answer in the Inputbox is: ' . $answerText . ' ';
            
        // if evereything fails
        } else {
            /* @todo Error handling */
            $answerText = "";
        }
        
        // for security reasons check the input from the frontend
        $answerText = filter_var($answerText, FILTER_SANITIZE_STRING);
        
        // store the answer of the participant in the selected dataset
        $c_selected->setEntered($answerText);
        
        foreach ($i_question->getAnswers() as $answer) {
            // store the correct answer in the selected dataset
            $c_selected->addAnswer($answer);
            
            if ($i_question->getQmode() == 3) {
	            // sum the the points of the current answer to the max. possible point until the current question
	            $c_maximum1 += $answer->getPoints();
	            
	            // if the answer is right
	            if (strtoupper(trim($answer->getTitle())) == strtoupper(trim($answerText))) {
	                $newPoints = $answer->getPoints();
	                if ($newPoints != 0) {
	                    $c_selected->addPoints($newPoints);
	                    $this->participant->addPoints($newPoints);
	                    $c_debug .= "\n" . '+' .$newPoints . 'P ';
	                }
	            }
	        }
        }
    }
    
    /**
     * Get the real IP address
     *
     * @return 	string	IP address
     */
    function getRealIpAddr()
    {
    	if (!$this->settings['user']['ipSave']) {
    		$ip = '0.0.0.1';
    	} elseif ($this->settings['user']['ipSave'] == 2) {
    		$ip = $_SERVER['REMOTE_ADDR'];
    	} elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
    		//check ip from share internet
    		$ip = $_SERVER['HTTP_CLIENT_IP'];
    	} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    		//to check ip is pass from proxy
    		$ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    	} else {
    		$ip = $_SERVER['REMOTE_ADDR'];
    	}
    	if ($this->settings['user']['ipSave'] && $this->settings['user']['ipAnonymous']) {
    		$pos = strrpos($ip, '.');
    		$ip = substr($ip, 0, $pos) . '.0';
    	}
    	return filter_var($ip, FILTER_VALIDATE_IP);
    }
    
    
    /**
     * action list
     *
     * @return void
     */
    public function listAction()
    {
    	$quizzes = $this->quizRepository->findAll();
    	$this->view->assign('quizzes', $quizzes);
    }
    
    /**
     * action default: ein Quiz oder alle Quizze anzeigen. Nur forward!
     *
     * @return void
     */
    public function defaultAction()
    {
    	$defaultQuizUid = $this->settings['defaultQuizUid'];
    	if (!$defaultQuizUid) {
    		$this->forward('list');
    	} else {
    		$quiz = $this->quizRepository->findOneByUid(intval($this->settings['defaultQuizUid']));
    		if ($quiz) {
    			$this->forward('show', NULL, NULL, array('quiz' => $quiz->getUid()));
    		} else {
    			$this->addFlashMessage(
    				LocalizationUtility::translate('error.quizNotFound', 'fp_masterquiz') . ' ' . intval($this->settings['defaultQuizUid']),
    				LocalizationUtility::translate('error.error', 'fp_masterquiz'),
    				\TYPO3\CMS\Core\Messaging\AbstractMessage::WARNING,
    				false
    				);
    			$this->forward('list');
    		}
    	}
    }
    
    /**
     * action show
     *
     * @param \Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz
     * @return void
     */
    public function showAction(\Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz)
    {
        $data = $this->doAll($quiz);
        $page = $data['page'];
        $pages = $data['pages'];
        
        $this->view->assign('debug', $data['debug']);
        $this->view->assign('quiz', $quiz);
        $this->view->assign('participant', $this->participant);
        $this->view->assign('page', $page);
        if ($pages > 0) {
        	$this->view->assign('pagePercent', intval(round(100*($page/$pages))));
        	$this->view->assign('pagePercentInclFinalPage', intval(round(100*($page/($pages+1)))));
        }
        $this->view->assign('nextPage', $data['nextPage']);
        $this->view->assign('pages', $pages);
        $this->view->assign('pagesInclFinalPage', ($pages+1));
        $this->view->assign('questions', $data['questions']);
        $this->view->assign('pageBasis', ($page-1) * $this->settings['pagebrowser']['itemsPerPage']);
        $this->view->assign('final', $data['final']);
        $this->view->assign('finalContent', $data['finalContent']);
        $this->view->assign('session', $data['session']);
        $this->view->assign('showAnswers', $data['showAnswers']);
        $this->view->assign('showAnswersNext', $data['showAnswersNext']);
        $this->view->assign("sysLanguageUid", $GLOBALS['TSFE']->sys_language_uid);
        $this->view->assign('uidOfPage', $GLOBALS['TSFE']->id);
        $this->view->assign('uidOfCE', $this->configurationManager->getContentObject()->data['uid']);
       // $this->view->assign("action", ($this->settings['ajax']) ? 'showAjax' : 'show');
    }

    /**
     * action showAjax
     *
     * @return void
     */
    public function showAjaxAction()
    {
    	// https://www.sklein-medien.de/tutorials/detail/erstellung-einer-typo3-extension-mit-ajax-aufruf/
    	$quizUid = $this->request->hasArgument('quiz') ? intval($this->request->getArgument('quiz')) : 0;
    	if ($quizUid) {
    		// vorerst mal
    		$this->settings['user']['useCookie'] = 0;
    		$quiz = $this->quizRepository->findOneByUid($quizUid);
    		$data = $this->doAll($quiz);
    		$page = $data['page'];
    		$pages = $data['pages'];
    		$from = 1 + (($page-1) * intval($this->settings['pagebrowser']['itemsPerPage']));
    		$to = ($page * intval($this->settings['pagebrowser']['itemsPerPage']));
    		
    		$this->view->assign('debug', $data['debug']);
    		$this->view->assign('quiz', $quiz);
    		$this->view->assign('participant', $this->participant);
    		$this->view->assign('page', $page);
    		if ($pages > 0) {
    			$this->view->assign('pagePercent', intval(round(100*($page/$pages))));
    			$this->view->assign('pagePercentInclFinalPage', intval(round(100*($page/($pages+1)))));
    		}
    		$this->view->assign('nextPage', $data['nextPage']);
    		$this->view->assign('pages', $pages);
    		$this->view->assign('pagesInclFinalPage', ($pages+1));
    		$this->view->assign('questions', $data['questions']);
    		$this->view->assign('pageBasis', ($page-1) * $this->settings['pagebrowser']['itemsPerPage']);
    		$this->view->assign('final', $data['final']);
    		$this->view->assign('finalContent', $data['finalContent']);
    		$this->view->assign('session', $data['session']);
    		$this->view->assign('showAnswers', $data['showAnswers']);
    		$this->view->assign('showAnswersNext', $data['showAnswersNext']);
    		$this->view->assign("sysLanguageUid", $GLOBALS['TSFE']->sys_language_uid);
    		$this->view->assign('uidOfPage', $GLOBALS['TSFE']->id);
			
    		$this->view->assign('from', $from);
    		$this->view->assign('to', $to);
    		$this->view->assign('uidOfCE', $this->request->hasArgument('uidOfCE') ? intval($this->request->getArgument('uidOfCE')) : 0);
    	} else {
    		$this->view->assign('error', 1);
    	}
    }
    
    /**
     * Action list for the backend
     *
     * @return 	void
     */
    function indexAction()
    {
    	$pid = (int)GeneralUtility::_GP('id');
    	$quizzes = $this->quizRepository->findFromPid($pid);
    	$this->view->assign('pid', $pid);
    	$this->view->assign('quizzes', $quizzes);
    }
    
    /**
     * action show for the backend
     *
     * @param \Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz
     * @return void
     */
    public function detailAction(\Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz)
    {
    	$this->view->assign('quiz', $quiz);
    	if ($this->request->hasArgument('prop')) {
    		$this->view->assign('prop', $this->request->getArgument('prop'));
    	} else {
    		$this->view->assign('prop', 0);
    	}
    	if ($this->request->hasArgument('user')) {
    		$this->view->assign('user', $this->request->getArgument('user'));
    	} else {
    		$this->view->assign('user', 0);
    	}
    	if ($this->request->hasArgument('chart')) {
    		$this->view->assign('chart', $this->request->getArgument('chart'));
    	} else {
    		$this->view->assign('chart', 0);
    	}
    }
    
    /**
     * action charts for the backend
     *
     * @param \Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz
     * @return void
     */
    public function chartsAction(\Fixpunkt\FpMasterquiz\Domain\Model\Quiz $quiz)
    {
    	$be = $this->request->hasArgument('be') ? TRUE : FALSE;
    	if ($be) {
    		$pid = (int)GeneralUtility::_GP('id');
    	} else {
    		$pid = (int)$GLOBALS['TSFE']->id;
    	}
    	$debug = '';
    	$allResults = [];
    	$selectedRepository = $this->objectManager->get('Fixpunkt\\FpMasterquiz\\Domain\\Repository\\SelectedRepository');
    	foreach ($quiz->getQuestions() as $oneQuestion) {
    	    $votes = 0;
    	    $questionID = $oneQuestion->getUid();
    	    if ($this->settings['debug']) {
    	        $debug .= "\nquestion :" . $questionID;
    	    }
    	    if ($be) {
    	        $allAnsweredQuestions = $selectedRepository->findFromPidAndQuestion($pid, $questionID);
    	    } else {
    	        $allAnsweredQuestions = $selectedRepository->findByQuestion($questionID);
    	    }
    		// alle Ergebnisse durchgehen:
    		foreach ($allAnsweredQuestions as $allAnswers) {
    			// alle Antworten auf diese Frage:
    			foreach ($allAnswers->getAnswers() as $oneAnswer) {
    				if ($this->settings['debug']) {
    					$debug .= "\n all: " . $oneAnswer->getTitle() . ': ' . $oneAnswer->getPoints() . "P";
    				}
    				$allResults[$questionID][$oneAnswer->getUid()]++;
    			}
    		}
    		// gesammeltes speichern bei: alle möglichen Antworten einer Frage
    		foreach ($oneQuestion->getAnswers() as $oneAnswer) {
    		    $thisVotes = intval($allResults[$questionID][$oneAnswer->getUid()]);
    		    $votes += $thisVotes;
    			$oneAnswer->setAllAnswers($thisVotes);
    		}
    		$oneQuestion->setAllAnswers($votes);
    		// Prozentwerte setzen
    		foreach ($oneQuestion->getAnswers() as $oneAnswer) {
    		    if ($this->settings['debug']) {
    		        $debug .= "\n percent: 100*" . $oneAnswer->getAllAnswers() . '/' . $votes;
    		    }
    		    $oneAnswer->setAllPercent( 100 * ($oneAnswer->getAllAnswers() / $votes) );
    		}
    	}
    	$this->view->assign('debug', $debug);
    	$this->view->assign('pid', $pid);
    	$this->view->assign('quiz', $quiz);
    }
    
}
