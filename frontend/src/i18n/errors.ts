import { translate } from "./index";
const messages: Record<string, string> = {
  UNAUTHENTICATED: "Please sign in again.",
  UNAUTHORIZED: "Please sign in again.",
  FORBIDDEN: "Your role does not have permission for this action.",
  INVALID_CREDENTIALS: "The phone number or PIN is incorrect.",
  INVALID_CHALLENGE: "This sign-in verification expired. Start sign-in again.",
  INVALID_TOTP: "The authenticator code is incorrect or expired.",
  INVALID_CODE: "The authenticator code is incorrect or expired.",
  VALIDATION_ERROR: "Check the highlighted fields and try again.",
  VALIDATION_FAILED: "Check the highlighted fields and try again.",
  DEVICE_NOT_APPROVED: "This device needs owner approval.",
  DEVICE_BLOCKED: "This device has been blocked.",
  INSUFFICIENT_STOCK: "There is not enough sellable stock for this quantity.",
  PACKAGING_VERSION_CHANGED:
    "This pack changed. Refresh the catalogue, remove its old cart line and add the current pack.",
  IDEMPOTENCY_CONFLICT:
    "This request was already used with different details. Recover the original request.",
  CREDIT_LIMIT_EXCEEDED:
    "This purchase exceeds the customer’s available credit.",
  TILL_NOT_OPEN: "Open your own till before continuing.",
  TILL_ALREADY_OPEN: "You already have an open till.",
  APPROVAL_REQUIRED: "Supervisor approval is required.",
  APPROVAL_EXPIRED: "Approval expired. Request approval again.",
  PAYMENT_NOT_CONFIGURED: "Selcom is not configured for this shop.",
  PAYMENT_NOT_CONFIRMED:
    "Payment is not confirmed. Check the recorded payment before collecting again.",
  IMAGE_STORAGE_UNAVAILABLE:
    "Private image storage is not configured for this shop.",
  TOO_MANY_REQUESTS: "Too many attempts. Wait before trying again.",
};
/** Stable codes supply meaning; unmapped contextual server details remain available. */
export function translatedApiError(
  code: string | undefined,
): string | undefined {
  return code && messages[code] ? translate(messages[code]) : undefined;
}
