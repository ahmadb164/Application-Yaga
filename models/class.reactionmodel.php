<?php if (!defined('APPLICATION')) exit();
/* Copyright 2013 Zachary Doll */

/**
 * Reactions are the actions a user takes against another user's content
 * 
 * Events: AfterReactionSave
 * 
 * @package Yaga
 * @since 1.0
 */

class ReactionModel extends Gdn_Model {
  
  /**
   * Used to cache the reactions
   * @var array
   */
  private static $_Reactions = array();
  
  /**
   * Defines the related database table name.
   */
  public function __construct() {
    parent::__construct('Reaction');
  }

  /**
   * Returns the reactions associated with the specified user content.
   * 
   * @todo Optimize this
   * @param int $ID
   * @param string $Type is the kind of ID. Valid: comment, discussion, activity
   */
  public function Get($ID, $Type) {
    if(in_array($Type, array('discussion', 'comment', 'activity')) && $ID > 0) {
      $ReactionSet = array();
      $ActionModel = Yaga::ActionModel();
      if(empty(self::$_Reactions[$Type . $ID])) {
        foreach($ActionModel->Get() as $Index => $Action) {
          $ReactionSet[$Index]->ActionID = $Action->ActionID;
          $ReactionSet[$Index]->Name = $Action->Name;
          $ReactionSet[$Index]->Description = $Action->Description;
          $ReactionSet[$Index]->Tooltip = $Action->Tooltip;
          $ReactionSet[$Index]->CssClass = $Action->CssClass;
          $ReactionSet[$Index]->AwardValue = $Action->AwardValue;
          $ReactionSet[$Index]->Permission = $Action->Permission;
          
          $Reactions = $this->SQL
                  ->Select('InsertUserID as UserID, DateInserted')
                  ->From('Reaction')
                  ->Where('ActionID', $Action->ActionID)
                  ->Where('ParentID', $ID)
                  ->Where('ParentType', $Type)
                  ->Get()
                  ->Result();
          
          foreach($Reactions as $Reaction) {
            $ReactionSet[$Index]->UserIDs[] = $Reaction->UserID;
            $ReactionSet[$Index]->Dates[] = $Reaction->DateInserted;
          }
          if(empty($ReactionSet[$Index]->UserIDs)) {
            $ReactionSet[$Index]->UserIDs = array();
          }
            
        }

        self::$_Reactions[$Type . $ID] = $ReactionSet;
      }
      return self::$_Reactions[$Type . $ID];
    }
    else {
      return NULL;
    }
  }

  /**
   * Return a list of reactions a user has received
   * 
   * @param int $ID
   * @param string $Type activity, comment, discussion
   * @param int $UserID
   * @return DataSet
   */
  public function GetByUser($ID, $Type, $UserID) {
    return $this->SQL
            ->Select()
            ->From('Reaction')
            ->Where('ParentID', $ID)
            ->Where('ParentType', $Type)
            ->Where('InsertUserID', $UserID)
            ->Get()
            ->FirstRow();
  }
  
  /**
   * Return the count of reactions received by a user
   * 
   * @param int $UserID
   * @param int $ActionID
   * @return DataSet
   */
  public function GetUserCount($UserID, $ActionID) {
    return $this->SQL
            ->Select()
            ->From('Reaction')
            ->Where('ActionID', $ActionID)
            ->Where('ParentAuthorID', $UserID)
            ->GetCount();
  }
  
  /**
   * Sets a users reaction against another user's content. A user can only react
   * in one way to each unique piece of content. This function makes sure to
   * enforce this rule
   * 
   * Events: AfterReactionSave
   * 
   * @param int $ID
   * @param string $Type activity, comment, discussion
   * @param int $AuthorID
   * @param int $UserID
   * @param int $ActionID
   * @return DataSet
   */
  public function Set($ID, $Type, $AuthorID, $UserID, $ActionID) {
    // clear the cache
    unset(self::$_Reactions[$Type . $ID]);

    $EventArgs = array('ParentID' => $ID, 'ParentType' => $Type, 'ParentUserID' => $AuthorID, 'InsertUserID' => $UserID, 'ActionID' => $ActionID);
    $ActionModel = Yaga::ActionModel();
    $NewAction = $ActionModel->GetByID($ActionID);
    $Points = $Score = $NewAction->AwardValue;
    $CurrentReaction = $this->GetByUser($ID, $Type, $UserID);
    if($CurrentReaction) {
      $OldAction = $ActionModel->GetByID($CurrentReaction->ActionID);
      
      if($ActionID == $CurrentReaction->ActionID) {
        // remove the record
        $Reaction = $this->SQL->Delete('Reaction', array('ParentID' => $ID,
                    'ParentType' => $Type,
                    'InsertUserID' => $UserID,
                    'ActionID' => $ActionID));
        $EventArgs['Exists'] = FALSE;
        $Score = 0;
        $Points = -1 * $OldAction->AwardValue;
      }
      else {
        // update the record
        $Reaction = $this->SQL
              ->Update('Reaction')
              ->Set('ActionID', $ActionID)
              ->Set('DateInserted', date(DATE_ISO8601))
              ->Where('ParentID', $ID)
              ->Where('ParentType', $Type)
              ->Where('InsertUserID', $UserID)
              ->Put();
        $EventArgs['Exists'] = TRUE;
        $Points = -1 * ($OldAction->AwardValue - $Points);
      }
    }
    else {
      // insert a record
      $Reaction = $this->SQL
              ->Insert('Reaction',
                      array('ActionID' => $ActionID,
                      'ParentID' =>  $ID,
                      'ParentType' => $Type,
                      'ParentAuthorID' => $AuthorID,
                      'InsertUserID' => $UserID,
                      'DateInserted' => date(DATE_ISO8601)));
      $EventArgs['Exists'] = TRUE;
    }
    
    // Update the parent item score
    $this->SetUserScore($ID, $Type, $UserID, $Score);
    // Give the user points commesurate with reaction activity
    UserModel::GivePoints($AuthorID, $Points, 'Reaction');
    $this->FireEvent('AfterReactionSave', $EventArgs);
    return $Reaction;
  }
  
  /**
   * This updates the items score for future use in ranking and a best of controller
   * 
   * @param int $ID The items ID
   * @param string $Type The type of the item (only supports 'discussion' and 'comment'
   * @param int $UserID The user that is scoring the item
   * @param int $Score What they give it
   * @return boolean Whether or not the the request was successful
   */
  private function SetUserScore($ID, $Type, $UserID, $Score) {
    $Model = FALSE;
    switch($Type) {
      default:
        return FALSE;
      case 'discussion':
        $Model = new DiscussionModel();
        break;
      case 'comment':
        $Model = new CommentModel();
        break;
    }
    
    if($Model) {
      $Model->SetUserScore($ID, $UserID, $Score);
      return TRUE;
    }
    else {
      return FALSE;
    }
  }
}