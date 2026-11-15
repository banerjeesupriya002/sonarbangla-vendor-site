(function () {
  "use strict";
  var API = (window.SBH_CONFIG.API || "").replace(/\/$/, "");
  var $ = function (s) { return document.querySelector(s); };
  var tok = new URLSearchParams(location.search).get("t") || "";
  function toast(m, bad) { var t = $("#toast"); t.textContent = m; t.className = "toast show" + (bad ? " bad" : ""); setTimeout(function () { t.className = "toast"; }, 4200); }
  function show(id) { ["loading", "form", "done", "bad"].forEach(function (x) { $("#" + x).hidden = x !== id; }); }
  function err(id, m) { var f = $("#" + id).closest(".field"); f.classList.toggle("err", !!m); f.querySelector(".msg").textContent = m || ""; }
  document.querySelectorAll(".field input").forEach(function (e) {
    e.addEventListener("input", function () { e.closest(".field").classList.toggle("filled", !!e.value); });
  });
  $("#ifsc").addEventListener("input", function () { this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ""); });
  ["acct", "acct2"].forEach(function (i) { $("#" + i).addEventListener("input", function () { this.value = this.value.replace(/\D/g, ""); }); });
  if (!tok) { show("bad"); return; }
  fetch(API + "/payout/" + encodeURIComponent(tok)).then(function (r) { return r.ok ? r.json() : Promise.reject(); })
    .then(function (j) { $("#hello").textContent = "Hello " + (j.owner || "there") + " — " + (j.brand || "your brand") + " is approved. Add your bank details below; we collect them only through this private link."; show("form"); })
    .catch(function () { show("bad"); });
  $("#go").addEventListener("click", function () {
    var v = function (i) { return $("#" + i).value.trim(); }, ok = true;
    ["holder", "bank", "ifsc", "acct", "acct2"].forEach(function (i) { err(i, ""); });
    if (v("holder").length < 3) { err("holder", "Enter the account holder name"); ok = false; }
    if (v("bank").length < 2) { err("bank", "Enter the bank name"); ok = false; }
    if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(v("ifsc"))) { err("ifsc", "Valid IFSC (AAAA0XXXXXX)"); ok = false; }
    if (!/^\d{9,18}$/.test(v("acct"))) { err("acct", "Valid account number (9–18 digits)"); ok = false; }
    if (v("acct") !== v("acct2")) { err("acct2", "Account numbers don't match"); ok = false; }
    if (!ok) { toast("Please fix the highlighted fields.", true); return; }
    var b = this; b.disabled = true;
    fetch(API + "/payout/" + encodeURIComponent(tok), { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ holder: v("holder"), bank: v("bank"), ifsc: v("ifsc"), account: v("acct"), website: v("website") }) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || "Failed"); return j; }); })
      .then(function () { show("done"); window.scrollTo(0, 0); })
      .catch(function (e) { toast(e.message || "Couldn't save. Please try again.", true); b.disabled = false; });
  });
})();
