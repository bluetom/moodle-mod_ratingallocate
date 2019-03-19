<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
 * Contains the algorithm for the distribution
 *
 * @package    raalgo_sdwithopt
 * @copyright  2019 Wwu
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace raalgo_sdwithopt;

defined('MOODLE_INTERNAL') || die();

class algorithm_impl extends \mod_ratingallocate\algorithm {

    /** @var array */
    protected $globalranking;
    /** @var choice[] */
    protected $choices = array();
    /** @var array */
    protected $ratings = array();
    /** @var user[] */
    protected $users = array();
    /** @var int */
    protected $sumcountmissingplaces;
    /** @var int */
    protected $sumcountmovableplaces;

    public function get_name() {
        return 'sdwithopt';
    }

    /**
     * Computes the distribution of students to choices based on the students ratings.
     * @param $choicerecords array[] array of all choices which are ratable in this ratingallocate.
     * @param $ratings array[] array of all relevant ratings.
     * @param $raters array[] array of all raters in course.
     * @return array mapping of choice ids to array of user ids.
     */
    public function compute_distribution($choicerecords, $ratings, $raters) {
        // minsize, maxsize, optional
        $this->choices = $choicerecords;
        $this->ratings = $ratings;
        $this->users = $raters;
        $this->check_feasibility();
        // Compute global ranking.
        $this->prepare_execution();

        do {
            $this->run_deferred_acceptance();
            $this->calculate_assignment_counts();
            if ($this->sumcountmissingplaces == 0) {
                // Found feasible solution.
                break;
            }
            if ($this->sumcountmissingplaces < $this->sumcountmovableplaces) {
                $this->reduce_choices_max_size($this->sumcountmissingplaces);
                continue;
            } else {
                $choice_closed = $this->close_optional_choice();
            }
        } while (true);

        return array();
    }

    /**
     * Runs the deferred acceptance algorithm on the current state.
     */
    protected function run_deferred_acceptance() {
        do {
            $this->application_by_students();
            $rejectionoccured = $this->rejection_by_choices();
        } while ($rejectionoccured);
    }

    protected function calculate_assignment_counts() {
        $this->sumcountmissingplaces = 0;
        $this->sumcountmovableplaces = 0;

        foreach ($this->choices as $choice) {
            if (count($choice->waitinglist) < $choice->minsize) {
                $choice->countmissingplaces = $choice->minsize - count($choice->waitinglist);
                $choice->countmoveableassignments = 0;
            } else {
                $choice->countmissingplaces = 0;
                $choice->countmoveableassignments = count($choice->waitinglist) - $choice->minsize;
            }
            $choice->countoptionalassignments = count($choice->waitinglist);
            $choice->countfreeplaces = $choice->maxsize - count($choice->waitinglist);

            // Fill global variables.
            $this->sumcountmissingplaces += $choice->countmissingplaces;
            $this->sumcountmovableplaces += $choice->countmoveableassignments;
        }
    }

    /**
     * Students apply at the next choice at which they were not previously rejected.
     * The users preferencelist is shortened by the choice he/she applies to.
     * The waitinglist is directly ordered based on the global ranking.
     */
    protected function application_by_students() {
        foreach ($this->users as $user) {
            if (!$user->currentchoice && count($user->preferencelist) > 0) {
                $nextchoice = array_shift($user->preferencelist);
                $user->currentchoice = $nextchoice;
                $this->choices[$nextchoice]->waitinglist[$this->globalranking[$user->id]] = $user->id;
            }
        }
    }

    /**
     * Choices reject students based on their max size and the global ranking.
     * @return bool true if any choice did reject a student.
     */
    protected function rejection_by_choices() {
        $rejectionoccured = false;
        foreach ($this->choices as $choice) {
            ksort($choice->waitinglist);
            while (count($choice->waitinglist) > $choice->maxsize) {
                $userid = array_pop($choice->waitinglist);
                $this->users[$userid]->currentchoice = null;
                $rejectionoccured = true;
            }
        }
        return $rejectionoccured;
    }

    /**
     * Initializes the datatstructures needed for the algorithm.
     * - It creates the global ranking.
     * - It creates empty waiting lists for choices.
     * - It creates and fills the preferencelist for all users.
     */
    protected function prepare_execution() {
        // Compute global ranking.
        $userids = array_keys($this->users);
        shuffle($userids);
        $this->globalranking = array();
        $counter = 0;
        foreach ($userids as $userid) {
            $this->globalranking[$userid] = $counter++;
        }
        // Prepare waiting lists.
        foreach ($this->choices as $choice) {
            $choice->waitinglist = array();
        }
        // Prepare preference list of raters. TODO: Testfälle schreiben!
        foreach ($this->users as $user) {
            // TODO: Filter out ratings with 0 value.
            $ratingsofuser = array_filter($this->ratings, function ($rating) use ($user) {
                return $user->id == $rating->userid;
            });
            usort($ratingsofuser, function ($a, $b) {
                if ($a->rating == $b->rating) {
                    return 0;
                }
                return ($a->rating < $b->rating) ? -1 : 1;
            });
            $user->preferencelist = array();
            foreach ($ratingsofuser as $rating) {
                $user->preferencelist[] = $rating->choiceid;
            }
        }
    }

    /**
     * Checks the feasibility of the problem.
     * If the problem isn't feasible it is adjusted accordingly.
     */
    protected function check_feasibility () {
        $sumoflowerbounds = array_reduce($this->choices, function ($sum, $choice) {
            if (!$choice->optional) {
                return $sum + $choice->minsize;
            }
            return $sum;
        });
        $sumofupperbounds = array_reduce($this->choices, function ($sum, $choice) {
            return $sum + $choice->maxsize;
        });
        $usercount = count($this->users);
        if ($usercount < $sumoflowerbounds) {
            throw new \Exception("unfeasible problem");
        }
        if ($usercount > $sumofupperbounds) {
            throw new \Exception("unfeasible problem");
        }
    }


}
