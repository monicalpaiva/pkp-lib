<?php
/**
 * @file classes/security/authorization/PKPSubmissionFileAccessPolicy.inc.php
 *
 * Copyright (c) 2000-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class PKPSubmissionFileAccessPolicy
 * @ingroup security_authorization
 *
 * @brief Base class to control (write) access to submissions and (read) access to
 * submission details in OMP.
 */

import('lib.pkp.classes.security.authorization.internal.ContextPolicy');
import('lib.pkp.classes.security.authorization.RoleBasedHandlerOperationPolicy');

// Define the bitfield for monograph file access levels
define('SUBMISSION_FILE_ACCESS_READ', 1);
define('SUBMISSION_FILE_ACCESS_MODIFY', 2);

class PKPSubmissionFileAccessPolicy extends ContextPolicy {

	/** var $_baseFileAccessPolicy the base file file policy before _SUB_EDITOR is considered */

	var $_baseFileAccessPolicy;

	/**
	 * Constructor
	 * @param $request PKPRequest
	 * @param $args array request parameters
	 * @param $roleAssignments array
	 * @param $mode int bitfield SUBMISSION_FILE_ACCESS_...
	 * @param $fileIdAndRevision string
	 * @param $submissionParameterName string the request parameter we expect
	 *  the submission id in.
	 */
	function PKPSubmissionFileAccessPolicy($request, $args, $roleAssignments, $mode, $fileIdAndRevision = null, $submissionParameterName = 'submissionId') {
		// TODO: Refine file access policies. Differentiate between
		// read and modify access using bitfield:
		// $mode & SUBMISSION_FILE_ACCESS_...

		parent::ContextPolicy($request);
		$this->_baseFileAccessPolicy = $this->buildFileAccessPolicy($request, $args, $roleAssignments, $mode, $fileIdAndRevision, $submissionParameterName);
	}

	/**
	 *
	 * @param PKPRequest $request
	 * @param array $args
	 * @param array $roleAssignments
	 * @param int bitfield $mode
	 * @param string $fileIdAndRevision
	 * @param string $submissionParameterName
	 */
	function buildFileAccessPolicy($request, $args, $roleAssignments, $mode, $fileIdAndRevision, $submissionParameterName) {
		// We need a submission matching the file in the request.
		import('lib.pkp.classes.security.authorization.internal.SubmissionRequiredPolicy');
		$this->addPolicy(new SubmissionRequiredPolicy($request, $args, $submissionParameterName));
		import('lib.pkp.classes.security.authorization.internal.SubmissionFileMatchesSubmissionPolicy');
		$this->addPolicy(new SubmissionFileMatchesSubmissionPolicy($request, $fileIdAndRevision));

		// Authors, managers and series editors potentially have
		// access to submission files. We'll have to define
		// differentiated policies for those roles in a policy set.
		$fileAccessPolicy = new PolicySet(COMBINING_PERMIT_OVERRIDES);


		//
		// Managerial role
		//
		if (isset($roleAssignments[ROLE_ID_MANAGER])) {
			// Managers have all access to all submissions.
			$fileAccessPolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, ROLE_ID_MANAGER, $roleAssignments[ROLE_ID_MANAGER]));
		}


		//
		// Author role
		//
		if (isset($roleAssignments[ROLE_ID_AUTHOR])) {
			// 1) Author role user groups can access whitelisted operations ...
			$authorFileAccessPolicy = new PolicySet(COMBINING_DENY_OVERRIDES);
			$authorFileAccessPolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, ROLE_ID_AUTHOR, $roleAssignments[ROLE_ID_AUTHOR]));

			// 2) ...if they are assigned to the workflow stage.  Note: This loads the application-specific policy class.
			import('classes.security.authorization.WorkflowStageAccessPolicy');
			$authorFileAccessPolicy->addPolicy(new WorkflowStageAccessPolicy($request, $args, $roleAssignments, 'submissionId', $request->getUserVar('stageId')));

			// 3) ...and if they meet one of the following requirements:
			$authorFileAccessOptionsPolicy = new PolicySet(COMBINING_PERMIT_OVERRIDES);

			// 3a) If the file was uploaded by the current user, allow...
			import('lib.pkp.classes.security.authorization.internal.SubmissionFileUploaderAccessPolicy');
			$authorFileAccessOptionsPolicy->addPolicy(new SubmissionFileUploaderAccessPolicy($request, $fileIdAndRevision));

			// 3b) ...or if the file is a file in a review round with requested revision decision, allow...
			// Note: This loads the application-specific policy class
			import('classes.security.authorization.internal.SubmissionFileRequestedRevisionRequiredPolicy');
			$authorFileAccessOptionsPolicy->addPolicy(new SubmissionFileRequestedRevisionRequiredPolicy($request, $fileIdAndRevision));

			// ...or if we don't want to modify the file...
			if (!($mode & SUBMISSION_FILE_ACCESS_MODIFY)) {

				// 3c) ...and the file is at submission stage...
				import('lib.pkp.classes.security.authorization.internal.SubmissionFileSubmissionStageRequiredPolicy');
				$authorFileAccessOptionsPolicy->addPolicy(new SubmissionFileSubmissionStageRequiredPolicy($request, $fileIdAndRevision));

				// 3d) ...or the file is a viewable reviewer response...
				import('lib.pkp.classes.security.authorization.internal.SubmissionFileViewableReviewerResponseRequiredPolicy');
				$authorFileAccessOptionsPolicy->addPolicy(new SubmissionFileViewableReviewerResponseRequiredPolicy($request, $fileIdAndRevision));

				// 3e) ...or if the file is part of a signoff assigned to the user, allow.
				import('lib.pkp.classes.security.authorization.internal.SubmissionFileAssignedAuditorAccessPolicy');
				$authorFileAccessOptionsPolicy->addPolicy(new SubmissionFileAssignedAuditorAccessPolicy($request, $fileIdAndRevision));
			}

			// Add the rules from 3)
			$authorFileAccessPolicy->addPolicy($authorFileAccessOptionsPolicy);

			$fileAccessPolicy->addPolicy($authorFileAccessPolicy);
		}


		//
		// Reviewer role
		//
		if (isset($roleAssignments[ROLE_ID_REVIEWER])) {
			// 1) Reviewers can access whitelisted operations ...
			$reviewerFileAccessPolicy = new PolicySet(COMBINING_DENY_OVERRIDES);
			$reviewerFileAccessPolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, ROLE_ID_REVIEWER, $roleAssignments[ROLE_ID_REVIEWER]));

			// 2) ...if they meet one of the following requirements:
			$reviewerFileAccessOptionsPolicy = new PolicySet(COMBINING_PERMIT_OVERRIDES);

			// 2a) If the file was uploaded by the current user, allow.
			import('lib.pkp.classes.security.authorization.internal.SubmissionFileUploaderAccessPolicy');
			$reviewerFileAccessOptionsPolicy->addPolicy(new SubmissionFileUploaderAccessPolicy($request, $fileIdAndRevision));

			// 2b) If the file is part of an assigned review, and we're not
			// trying to modify it, allow.
			import('lib.pkp.classes.security.authorization.internal.SubmissionFileAssignedReviewerAccessPolicy');
			if (!($mode & SUBMISSION_FILE_ACCESS_MODIFY)) {
				$reviewerFileAccessOptionsPolicy->addPolicy(new SubmissionFileAssignedReviewerAccessPolicy($request, $fileIdAndRevision));
			}

			// Add the rules from 2)
			$reviewerFileAccessPolicy->addPolicy($reviewerFileAccessOptionsPolicy);

			// Add this policy set
			$fileAccessPolicy->addPolicy($reviewerFileAccessPolicy);
		}


		//
		// Assistant role.
		//
		if (isset($roleAssignments[ROLE_ID_ASSISTANT])) {
			// 1) Assistants can access whitelisted operations...
			$contextAssistantFileAccessPolicy = new PolicySet(COMBINING_DENY_OVERRIDES);
			$contextAssistantFileAccessPolicy->addPolicy(new RoleBasedHandlerOperationPolicy($request, ROLE_ID_ASSISTANT, $roleAssignments[ROLE_ID_ASSISTANT]));

			// 2) ... but only if they have been assigned to the submission workflow.
			// Note: This loads the application-specific policy class
			import('classes.security.authorization.WorkflowStageAccessPolicy');
			$contextAssistantFileAccessPolicy->addPolicy(new WorkflowStageAccessPolicy($request, $args, $roleAssignments, 'submissionId', $request->getUserVar('stageId')));
			$fileAccessPolicy->addPolicy($contextAssistantFileAccessPolicy);
		}

		return $fileAccessPolicy;
	}
}

?>
