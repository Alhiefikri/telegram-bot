import assert from "node:assert/strict";
import test from "node:test";
import adminDashboard from "../../resources/js/admin-dashboard.js";

const dashboard = adminDashboard({});

test("paid legacy credentials preserve whitespace and literal HTML", () => {
  assert.equal(
    dashboard.stockCredentialText({
      status: "PAID",
      editable: false,
      secret: " user|<secret>\nsecond line ",
    }),
    " user|<secret>\nsecond line "
  );
});

test("structured credentials include the exact password and only the selected access method", () => {
  for (const [method, label] of [
    ["otp_mail_link", "OTP Mail Link"],
    ["auth_token", "Auth Token"],
  ]) {
    assert.equal(
      dashboard.stockCredentialText({
        status: "PAID",
        editable: false,
        credential_format: "structured_account",
        credential_payload: {
          email: "account@example.test",
          password: " pass\n<secret> ",
          access_method: method,
          access_value: "private-value",
          reader_mail_link: "https://reader.example.test/private",
          tutorial_override: "First step\nSecond step",
        },
      }),
      `Email: account@example.test\nPassword:  pass\n<secret> \n${label}: private-value\nReader Mail Link: https://reader.example.test/private\nTutorial: First step\nSecond step`
    );
  }
  assert.equal(
    dashboard.stockCredentialText({
      credential_format: "structured_account",
      credential_payload: {
        email: "account@example.test",
        password: "pass",
        access_method: "none",
        access_value: null,
      },
    }),
    "Email: account@example.test\nPassword: pass"
  );
});

test("unreadable payloads offer no credential text", () => {
  assert.equal(
    dashboard.stockCredentialText({
      credential_format: "structured_account",
      credential_payload: null,
    }),
    ""
  );
  assert.equal(
    dashboard.stockCredentialText({
      credential_format: "repair_required",
      secret: null,
    }),
    ""
  );
});
