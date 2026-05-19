/**
 * Step components barrel export.
 *
 * Both wizard (admin/wizard.php) and settings (admin/settings.php)
 * import these — same components, inSettings prop flips the chrome.
 * Sub-PR 2.E.2 adds Step3..Step6.
 */

export { Step1Connect, type Step1ConnectProps } from './Step1Connect';
export { Step2Subscribers, type Step2SubscribersProps } from './Step2Subscribers';
