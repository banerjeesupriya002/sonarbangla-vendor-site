/* Sonar Bangla Haat — Vendor Onboarding (static front-end, talks to the WordPress plugin). */
(function () {
  "use strict";
  var CFG = window.SBH_CONFIG || {};
  var API = (CFG.API || "").replace(/\/$/, "");
  var TOTAL = 7, LS = "sbh_vendor_draft_v2";
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var STATES = ["Andaman and Nicobar Islands","Andhra Pradesh","Arunachal Pradesh","Assam","Bihar","Chandigarh","Chhattisgarh","Dadra and Nagar Haveli and Daman and Diu","Delhi","Goa","Gujarat","Haryana","Himachal Pradesh","Jammu and Kashmir","Jharkhand","Karnataka","Kerala","Ladakh","Lakshadweep","Madhya Pradesh","Maharashtra","Manipur","Meghalaya","Mizoram","Nagaland","Odisha","Puducherry","Punjab","Rajasthan","Sikkim","Tamil Nadu","Telangana","Tripura","Uttar Pradesh","Uttarakhand","West Bengal"];
  // GSTIN first two digits -> state
  var GST_STATE = {"01":"Jammu and Kashmir","02":"Himachal Pradesh","03":"Punjab","04":"Chandigarh","05":"Uttarakhand","06":"Haryana","07":"Delhi","08":"Rajasthan","09":"Uttar Pradesh","10":"Bihar","11":"Sikkim","12":"Arunachal Pradesh","13":"Nagaland","14":"Manipur","15":"Mizoram","16":"Tripura","17":"Meghalaya","18":"Assam","19":"West Bengal","20":"Jharkhand","21":"Odisha","22":"Chhattisgarh","23":"Madhya Pradesh","24":"Gujarat","26":"Dadra and Nagar Haveli and Daman and Diu","27":"Maharashtra","29":"Karnataka","30":"Goa","31":"Lakshadweep","32":"Kerala","33":"Tamil Nadu","34":"Puducherry","35":"Andaman and Nicobar Islands","36":"Telangana","37":"Andhra Pradesh","38":"Ladakh"};
  var FIELDS = ["email","owner_name","mobile","whatsapp","brand_name","legal_name","pan_number","gstin","address_line_1","address_line_2","district","state","pin_code","brand_story","fssai_number"];

  var S = { token: "", step: 1, gst: "", fssai: "", cats: [], data: {}, sid: "", gstOk: false };
  var LOCK = ["legal_name", "pan_number", "address_line_1", "address_line_2", "district", "state", "pin_code"];
  var files = {};
  var saveTimer = null;

  /* ---------- helpers ---------- */
  function toast(msg, bad) {
    var t = $("#toast"); t.textContent = msg; t.className = "toast show" + (bad ? " bad" : "");
    clearTimeout(toast._t); toast._t = setTimeout(function () { t.className = "toast"; }, 4200);
  }
  function val(id) { var e = $("#" + id); return e ? e.value.trim() : ""; }
  function setVal(id, v) { var e = $("#" + id); if (!e) return; e.value = v == null ? "" : v; refresh(e); }
  function refresh(e) { var f = e.closest(".field"); if (f) f.classList.toggle("filled", !!e.value || e.tagName === "SELECT" && !!e.value); }
  function err(id, msg) {
    var e = $("#" + id), f = e && e.closest(".field"); if (!f) return;
    f.classList.toggle("err", !!msg); var m = $(".msg", f); if (m) m.textContent = msg || "";
  }
  function clearErr(root) { $$(".field.err", root).forEach(function (f) { f.classList.remove("err"); }); }
  function api(path, opts) {
    opts = opts || {};
    var init = { method: opts.method || "GET", headers: {} };
    if (opts.json) { init.method = "POST"; init.headers["Content-Type"] = "application/json"; init.body = JSON.stringify(opts.json); }
    if (opts.form) { init.body = opts.form; init.method = "POST"; }
    return fetch(API + path, init).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error(j.message || "Request failed"); e.code = j.code; e.status = r.status; throw e; }
        return j;
      });
    });
  }
  function normPhone(v) { var d = String(v || "").replace(/[^\d]/g, ""); if (d.length === 12 && d.indexOf("91") === 0) d = d.slice(2); if (d.length === 11 && d[0] === "0") d = d.slice(1); return d; }
  var okPhone = function (v) { return /^[6-9]\d{9}$/.test(normPhone(v)); };
  var okEmail = function (v) { return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v); };
  var okPAN = function (v) { return /^[A-Z]{5}[0-9]{4}[A-Z]$/.test(v); };
  function okGSTIN(g) {
    if (!/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(g)) return false;
    var cs = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ", sum = 0;
    for (var i = 0; i < 14; i++) { var v = cs.indexOf(g[i]) * (i % 2 === 0 ? 1 : 2); sum += Math.floor(v / 36) + (v % 36); }
    return cs[(36 - (sum % 36)) % 36] === g[14];
  }

  /* ---------- state persistence ---------- */
  function collect() { FIELDS.forEach(function (k) { var e = $("#" + k); if (e) S.data[k] = e.value.trim(); }); S.data.gst_ok = S.gstOk ? 1 : 0; S.data.gst_registered = S.gst; S.data.fssai_has = S.fssai; S.data.product_categories = S.cats.slice(); }
  function persist(remote) {
    collect();
    try { localStorage.setItem(LS, JSON.stringify({ token: S.token, step: S.step, gst: S.gst, fssai: S.fssai, cats: S.cats, data: S.data })); } catch (e) {}
    if (remote && S.token) {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(function () { api("/draft/save", { json: { token: S.token, step: S.step, data: S.data } }).catch(function () {}); }, 400);
    }
  }
  function restore(o) {
    if (!o) return;
    S.token = o.token || S.token; S.gst = o.gst || ""; S.fssai = o.fssai || ""; S.cats = o.cats || []; S.data = o.data || {};
    FIELDS.forEach(function (k) { if (S.data[k] != null) setVal(k, S.data[k]); });
    S.gstOk = S.gst === "yes" && (!!Number(S.data.gst_ok) || (okGSTIN(S.data.gstin || "") && !!S.data.legal_name && !!S.data.pin_code));
    $$("[data-gst]").forEach(function (b) { b.classList.toggle("on", b.dataset.gst === S.gst); });
    $$("[data-fssai]").forEach(function (b) { b.classList.toggle("on", b.dataset.fssai === S.fssai); });
    buildChips(); applyGst(false); applyFssai();
  }

  var _pre = false;
  function preloadPhotos() {
    if (_pre) return; _pre = true;
    var list = (window.SBH_CAT_PHOTOS || []).slice(), idle = window.requestIdleCallback || function (f) { return setTimeout(f, 300); };
    (function next() { if (!list.length) return; idle(function () { var i = new Image(); i.decoding = "async"; i.src = "assets/cats/" + list.shift() + ".jpg"; next(); }); })();
  }

  /* ---------- navigation ---------- */
  function go(n, push) {
    S.step = n;
    $$(".slide").forEach(function (s) { s.classList.toggle("on", +s.dataset.step === n); });
    $("#pcur").textContent = String(n).padStart(2, "0");
    $("#pfill").style.transform = "scaleX(" + Math.max(0, Math.min(1, (n - 1) / (TOTAL - 1))) + ")";
    if (n === 6) buildSummary();
    if (n >= 3) preloadPhotos();
    window.scrollTo({ top: 0, behavior: "smooth" });
    if (push !== false) { try { history.replaceState(null, "", location.pathname + (S.token && n < 7 ? "" : "")); } catch (e) {} }
    if (n < 7) persist(n > 3);
  }

  /* ---------- step 1 ---------- */
  function initWelcome() {
    var words = ["মাটির স্বাদ","Handmade in Bengal","Founding Vendor","Authentic Bengal","নিজস্ব ব্র্যান্ড","Nolen Gur","Terracotta","Tant Sarees"];
    var html = ""; for (var i = 0; i < 4; i++) words.forEach(function (w) { html += "<span>" + w + "</span><span>·</span>"; });
    $("#marquee").innerHTML = html;
    $("#start").addEventListener("click", function () { go(2); });
    $("#resumeSend").addEventListener("click", function () {
      var em = val("resumeEmail");
      if (!okEmail(em)) { toast("Please enter a valid email address.", true); return; }
      var b = this; b.disabled = true;
      api("/draft/resume", { json: { email: em } }).then(function () { toast("If a saved application exists for this email, a resume link is on its way."); })
        .catch(function () { toast("Couldn't send the link right now. Please try again.", true); })
        .then(function () { b.disabled = false; });
    });
    $("#yr").textContent = new Date().getFullYear();
  }

  /* ---------- step 3: signup ---------- */
  function validSignup() {
    clearErr($("#s3")); var ok = true;
    if (!okEmail(val("email"))) { err("email", "Enter a valid email address"); ok = false; }
    if (val("owner_name").length < 2) { err("owner_name", "Enter the owner's full name"); ok = false; }
    if (!okPhone(val("mobile"))) { err("mobile", "Enter a valid 10-digit mobile number"); ok = false; }
    if (val("whatsapp") && !okPhone(val("whatsapp"))) { err("whatsapp", "Enter a valid 10-digit number"); ok = false; }
    return ok;
  }
  function initSignup() {
    $("#signupNext").addEventListener("click", function () {
      if (!validSignup()) { toast("Please fix the highlighted fields.", true); return; }
      var b = this; b.disabled = true; var old = b.innerHTML; b.innerHTML = '<span class="spin"></span>Saving';
      collect();
      api("/draft", { json: { token: S.token, email: val("email"), owner_name: val("owner_name"), mobile: normPhone(val("mobile")), whatsapp: normPhone(val("whatsapp")), website: val("website") } })
        .then(function (r) { S.token = r.token; if (r.emailed) toast("We've emailed you a resume link — just in case."); go(4); })
        .catch(function (e) { toast(e.message || "Couldn't save. Please try again.", true); })
        .then(function () { b.disabled = false; b.innerHTML = old; });
    });
  }

  /* ---------- step 4: GST + address ---------- */
  function applyGst(load) {
    var yes = S.gst === "yes";
    $("#gstPanel").hidden = !yes;
    $("#dz_gst").hidden = !yes;
    $("#pan_number").closest(".field").querySelector("label").textContent = "PAN Number *";
    if (!yes) S.gstOk = false;
    $("#bizForm").hidden = !S.gst; // nothing chosen yet: no manual entry
    lockFields();
    if (yes && load !== false && !$("#capImg img")) loadCaptcha();
  }
  // GST "Yes": business/address details come only from the GST portal lookup (no manual typing).
  function lockFields() {
    var yes = S.gst === "yes", hint = $("#gstLockHint");
    LOCK.forEach(function (k) {
      var e = $("#" + k); if (!e) return;
      var lock = yes && (!S.gstOk || !!e.value || k === "address_line_2");
      if (e.tagName === "SELECT") e.disabled = lock; else e.readOnly = lock;
      e.closest(".field").classList.toggle("locked", lock);
    });
    if (hint) { hint.hidden = !yes; hint.textContent = S.gstOk ? "These details were fetched from the GST portal. Any field left empty can be completed by you." : "Enter your GSTIN and the captcha above — your business and address details will be filled automatically from the GST portal."; }
  }
  function loadCaptcha() {
    var box = $("#capImg"); box.textContent = "Loading…"; S.sid = ""; setVal("gstCaptcha", "");
    api("/gst/captcha").then(function (r) { S.sid = r.sid; box.innerHTML = ""; var im = new Image(); im.alt = "GST portal captcha"; im.src = r.image; box.appendChild(im); })
      .catch(function () { box.textContent = "Unavailable"; setStatus("The GST portal lookup is unavailable right now. Please fill the details manually below.", "bad"); });
  }
  function setStatus(m, c) { var s = $("#gstStatus"); s.textContent = m || ""; s.className = "status" + (c ? " " + c : ""); }
  function fillFromGST(d) {
    var map = { legal_name: d.legal_name, brand_name: d.trade_name || d.legal_name, pan_number: d.pan, address_line_1: d.address_line_1, address_line_2: d.address_line_2, district: d.district, pin_code: d.pin_code };
    Object.keys(map).forEach(function (k) { if (map[k]) { if (k === "brand_name" && val("brand_name")) return; setVal(k, map[k]); } });
    if (d.state) setVal("state", d.state);
  }
  function initGst() {
    $$("[data-gst]").forEach(function (b) { b.addEventListener("click", function () {
      S.gst = b.dataset.gst; $$("[data-gst]").forEach(function (x) { x.classList.toggle("on", x === b); }); applyGst(true); persist();
    }); });
    $("#capRefresh").addEventListener("click", loadCaptcha);
    $("#gstin").addEventListener("input", function () {
      this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, "");
      if (S.gstOk && this.value !== S.data.gstin_ok_for) { S.gstOk = false; LOCK.forEach(function (k) { var e = $("#" + k); if (e) { if (e.tagName === "SELECT") e.disabled = false; e.readOnly = false; setVal(k, ""); } }); lockFields(); }
    });
    $("#pan_number").addEventListener("input", function () { this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, ""); });
    $("#gstFetch").addEventListener("click", function () {
      var g = val("gstin"), c = val("gstCaptcha"); err("gstin", "");
      if (!okGSTIN(g)) { err("gstin", "Enter a valid 15-character GSTIN"); return; }
      if (!c) { setStatus("Type the captcha shown above.", "bad"); return; }
      var b = this; b.disabled = true; setStatus("Fetching from the GST portal…");
      api("/gst/lookup", { json: { sid: S.sid, gstin: g, captcha: c } }).then(function (r) {
        if (r.data) { fillFromGST(r.data); S.gstOk = true; S.data.gstin_ok_for = g; lockFields(); persist(true); }
        setStatus("✓ Details fetched and filled below" + (r.data && r.data.status ? " · GST status: " + r.data.status : "") + ".", "ok");
        if (!r.data || !r.data.address_line_1) setStatus("GSTIN verified. The GST portal didn't return a few details — please complete the empty fields.", "ok");
        loadCaptcha();
      }).catch(function (e) {
        var m = e.message || "Lookup failed.";
        if (e.code === "bad_captcha") m = "Captcha didn't match. Please try the new one.";
        if (e.code === "unavailable") m = "The GST portal is unavailable right now. You can fill the details manually below.";
        setStatus(m, "bad"); loadCaptcha();
      }).then(function () { b.disabled = false; });
    });
    $("#s4Next").addEventListener("click", function () {
      var root = $("#s4"); clearErr(root); var ok = true;
      if (!S.gst) { toast("Please tell us whether you are GST registered.", true); return; }
      var g = val("gstin");
      if (S.gst === "yes" && !S.gstOk) { err("gstin", "Fetch your details from the GST portal first"); toast("Please fetch your details from the GST portal using the captcha.", true); return; }
      if (S.gst === "yes") {
        if (!okGSTIN(g)) { err("gstin", "Enter a valid 15-character GSTIN"); ok = false; }
        else { var pg = g.slice(2, 12); if (val("pan_number") && val("pan_number") !== pg) { err("pan_number", "PAN doesn't match the GSTIN"); ok = false; } if (!val("pan_number")) setVal("pan_number", pg); if (!val("state") && GST_STATE[g.slice(0, 2)]) setVal("state", GST_STATE[g.slice(0, 2)]); }
      }
      if (val("brand_name").length < 2) { err("brand_name", "Enter your brand / business name"); ok = false; }
      if (!okPAN(val("pan_number"))) { err("pan_number", "Valid PAN (AAAAA0000A)"); ok = false; }
      if (val("address_line_1").length < 3) { err("address_line_1", "Enter address line 1"); ok = false; }
      if (val("district").length < 2) { err("district", "Enter district"); ok = false; }
      if (!val("state")) { err("state", "Select state"); ok = false; }
      if (!/^\d{6}$/.test(val("pin_code"))) { err("pin_code", "Valid 6-digit pin code"); ok = false; }
      if (!ok) { toast("Please fix the highlighted fields.", true); return; }
      go(5);
    });
  }

  /* ---------- step 5: categories + FSSAI ---------- */
  /* Category illustrations (original flat art, theme colours) */
  var T = "#a73a22", M = "#d89e25", R = "#691c14", I = "#3b1713", C = "#f5efe6", G = "#7d8f4e", B = "#b9774a";
  var ART = {
    rice: '<ellipse cx="60" cy="62" rx="30" ry="6" fill="' + I + '" opacity=".12"/><path d="M30 46h60c0 18-12 26-30 26S30 64 30 46z" fill="' + T + '"/><path d="M34 46c4-12 48-12 52 0z" fill="#fff6e4"/><g fill="#fff"><ellipse cx="48" cy="40" rx="3" ry="1.6"/><ellipse cx="60" cy="37" rx="3" ry="1.6"/><ellipse cx="72" cy="40" rx="3" ry="1.6"/></g><path d="M96 66V30" stroke="' + G + '" stroke-width="2"/><g fill="' + M + '"><ellipse cx="96" cy="30" rx="3" ry="6"/><ellipse cx="91" cy="38" rx="3" ry="6" transform="rotate(-25 91 38)"/><ellipse cx="101" cy="38" rx="3" ry="6" transform="rotate(25 101 38)"/></g>',
    oil: '<rect x="44" y="26" width="32" height="46" rx="8" fill="' + M + '"/><rect x="52" y="14" width="16" height="16" rx="3" fill="' + R + '"/><rect x="48" y="40" width="24" height="18" rx="3" fill="#fff6e4"/><path d="M54 49h12" stroke="' + T + '" stroke-width="2"/><g fill="' + M + '"><circle cx="24" cy="58" r="5"/><circle cx="96" cy="52" r="5"/></g><g fill="' + G + '"><circle cx="24" cy="58" r="2"/><circle cx="96" cy="52" r="2"/></g>',
    ghee: '<ellipse cx="60" cy="70" rx="28" ry="5" fill="' + I + '" opacity=".12"/><path d="M34 36h52v26c0 8-10 10-26 10S34 70 34 62z" fill="' + M + '"/><rect x="30" y="26" width="60" height="12" rx="4" fill="' + R + '"/><rect x="42" y="46" width="36" height="16" rx="3" fill="#fff6e4"/><path d="M48 54h24" stroke="' + T + '" stroke-width="2"/>',
    spice: '<g><path d="M14 64h34c0 8-8 12-17 12S14 72 14 64z" fill="' + T + '"/><path d="M16 64c3-10 29-10 32 0z" fill="#c9482c"/></g><g><path d="M44 58h34c0 8-8 12-17 12S44 66 44 58z" fill="' + M + '"/><path d="M46 58c3-10 29-10 32 0z" fill="#e8b445"/></g><g><path d="M74 64h34c0 8-8 12-17 12S74 72 74 64z" fill="' + G + '"/><path d="M76 64c3-10 29-10 32 0z" fill="#9aab68"/></g>',
    mixture: '<path d="M28 44h64c0 20-14 30-32 30S28 64 28 44z" fill="' + R + '"/><g fill="' + M + '"><rect x="36" y="32" width="14" height="5" rx="2" transform="rotate(-20 43 34)"/><rect x="52" y="28" width="16" height="5" rx="2"/><rect x="70" y="32" width="14" height="5" rx="2" transform="rotate(22 77 34)"/><rect x="44" y="38" width="14" height="5" rx="2" transform="rotate(12 51 40)"/><rect x="62" y="38" width="14" height="5" rx="2" transform="rotate(-14 69 40)"/></g><g fill="' + T + '"><circle cx="50" cy="30" r="2.5"/><circle cx="72" cy="28" r="2.5"/><circle cx="60" cy="36" r="2.5"/></g>',
    tea: '<path d="M28 44h50v14c0 12-10 18-25 18S28 70 28 58z" fill="' + T + '"/><path d="M78 48h6c6 0 8 4 8 8s-3 8-9 8h-5" fill="none" stroke="' + T + '" stroke-width="5"/><ellipse cx="53" cy="44" rx="25" ry="5" fill="' + B + '"/><path d="M42 36c-4-6 4-8 0-14M54 36c-4-6 4-8 0-14M66 36c-4-6 4-8 0-14" fill="none" stroke="' + I + '" stroke-width="2" stroke-linecap="round" opacity=".35" class="steam"/><path d="M96 68c8-2 14-10 14-18-8 0-14 8-14 18z" fill="' + G + '"/>',
    snack: '<ellipse cx="60" cy="66" rx="40" ry="8" fill="' + C + '" stroke="' + B + '" stroke-width="1.5"/><g fill="' + M + '" stroke="#b27d12" stroke-width="1"><path d="M30 58l10-12 10 12-10 8z"/><path d="M52 52l10-14 10 14-10 9z"/><path d="M72 58l10-12 10 12-10 8z"/><path d="M46 62l8-9 8 9-8 6z" fill="#e8b445"/></g>',
    bori: '<ellipse cx="60" cy="64" rx="42" ry="9" fill="' + B + '" opacity=".35"/><g fill="#f0dcb8" stroke="' + B + '" stroke-width="1.2"><circle cx="38" cy="54" r="9"/><circle cx="58" cy="48" r="9"/><circle cx="78" cy="54" r="9"/><circle cx="48" cy="62" r="8"/><circle cx="70" cy="63" r="8"/></g><g fill="' + B + '" opacity=".5"><circle cx="36" cy="52" r="1.4"/><circle cx="41" cy="56" r="1.4"/><circle cx="56" cy="46" r="1.4"/><circle cx="61" cy="50" r="1.4"/><circle cx="76" cy="52" r="1.4"/></g>',
    achar: '<rect x="36" y="30" width="48" height="44" rx="8" fill="#e9d6b8"/><rect x="40" y="38" width="40" height="32" rx="5" fill="' + T + '"/><g fill="' + M + '"><circle cx="50" cy="48" r="3"/><circle cx="62" cy="56" r="3"/><circle cx="70" cy="46" r="3"/><circle cx="52" cy="62" r="3"/></g><path d="M32 30h56l-6-10H38z" fill="' + R + '"/><path d="M34 30c8 8 44 8 52 0" fill="none" stroke="' + M + '" stroke-width="2"/>',
    jaggery: '<g stroke="' + I + '" stroke-opacity=".25" stroke-width="1"><path d="M24 56l16-14 26 4 4 18-18 10z" fill="#9a5b22"/><path d="M62 46l22-8 16 12-6 18-24 4z" fill="#b8742d"/><path d="M50 62l18 0 8 14-22 2z" fill="#7d4617"/></g><path d="M34 52l12 2M72 46l10 6" stroke="#e0a05a" stroke-width="2" stroke-linecap="round"/>',
    sweet: '<ellipse cx="60" cy="68" rx="42" ry="8" fill="' + C + '" stroke="' + B + '" stroke-width="1.5"/><g><circle cx="40" cy="58" r="12" fill="#fff7ea" stroke="#f0dcc0" stroke-width="1.5"/><circle cx="62" cy="52" r="12" fill="#fff7ea" stroke="#f0dcc0" stroke-width="1.5"/><circle cx="82" cy="58" r="12" fill="#fff7ea" stroke="#f0dcc0" stroke-width="1.5"/></g><path d="M36 56c3-3 6-3 8 0M58 50c3-3 6-3 8 0M78 56c3-3 6-3 8 0" stroke="#f0dcc0" stroke-width="2" fill="none" stroke-linecap="round"/><circle cx="62" cy="40" r="3" fill="' + T + '"/>',
    pithe: '<ellipse cx="60" cy="70" rx="42" ry="7" fill="' + I + '" opacity=".1"/><circle cx="38" cy="52" r="15" fill="#e9c27a"/><g fill="#fff6e4" opacity=".9"><circle cx="32" cy="46" r="2"/><circle cx="42" cy="44" r="2"/><circle cx="44" cy="54" r="2"/><circle cx="34" cy="56" r="2"/><circle cx="38" cy="50" r="2"/></g><path d="M62 58c0-16 36-16 36 0z" fill="#f0e2c6" stroke="' + B + '" stroke-width="1.5"/><path d="M62 58h36" stroke="' + B + '" stroke-width="1.5"/><path d="M68 54c4-4 8-4 12 0M82 54c4-4 8-4 12 0" stroke="' + B + '" stroke-width="1.4" fill="none"/>',
    craft: '<ellipse cx="60" cy="74" rx="26" ry="5" fill="' + I + '" opacity=".12"/><path d="M40 34h40l6 10c4 8 2 24-6 28H40c-8-4-10-20-6-28z" fill="' + T + '"/><rect x="36" y="28" width="48" height="9" rx="4" fill="#8b2f1c"/><path d="M40 52h40M38 60h44" stroke="' + M + '" stroke-width="3" stroke-dasharray="4 4"/><g fill="' + M + '"><circle cx="48" cy="46" r="2.5"/><circle cx="60" cy="46" r="2.5"/><circle cx="72" cy="46" r="2.5"/></g><path d="M52 28c0-8 16-8 16 0" fill="none" stroke="' + G + '" stroke-width="3"/>',
    artisan: '<rect x="28" y="22" width="64" height="54" rx="4" fill="' + R + '"/><rect x="34" y="28" width="52" height="42" fill="none" stroke="' + M + '" stroke-width="2"/><g stroke="' + M + '" stroke-width="2" fill="none"><path d="M60 32l12 12-12 12-12-12z"/><path d="M60 40l5 5-5 5-5-5z" fill="' + M + '"/></g><g fill="' + M + '"><circle cx="42" cy="36" r="2"/><circle cx="78" cy="36" r="2"/><circle cx="42" cy="62" r="2"/><circle cx="78" cy="62" r="2"/></g><path d="M34 70h52" stroke="' + T + '" stroke-width="3" stroke-dasharray="3 3"/>',
    hamper: '<ellipse cx="60" cy="74" rx="36" ry="5" fill="' + I + '" opacity=".12"/><rect x="30" y="44" width="60" height="30" rx="3" fill="' + T + '"/><rect x="26" y="34" width="68" height="14" rx="3" fill="#c9482c"/><rect x="55" y="34" width="10" height="40" fill="' + M + '"/><path d="M60 34c-12-14-26-4-16 2 4 2 12 0 16-2zM60 34c12-14 26-4 16 2-4 2-12 0-16-2z" fill="' + M + '"/>',
    incense: '<path d="M40 70h40l-4 8H44z" fill="' + B + '"/><ellipse cx="60" cy="70" rx="20" ry="4" fill="#8b5a36"/><g stroke="' + R + '" stroke-width="2.5" stroke-linecap="round"><path d="M52 68L46 28"/><path d="M60 68V24"/><path d="M68 68l6-40"/></g><g fill="' + M + '"><circle cx="46" cy="28" r="2"/><circle cx="60" cy="24" r="2"/><circle cx="74" cy="28" r="2"/></g><path d="M46 22c-6-6 6-8 0-16M60 18c-6-6 6-8 0-16M74 22c-6-6 6-8 0-16" fill="none" stroke="' + I + '" stroke-width="2" stroke-linecap="round" opacity=".3" class="steam"/>',
    drink: '<path d="M38 36h44l-4 38c0 4-6 6-18 6s-18-2-18-6z" fill="#c07a4a"/><path d="M38 36h44" stroke="#a5623a" stroke-width="3"/><path d="M42 46h36" stroke="#e1a578" stroke-width="3"/><ellipse cx="60" cy="36" rx="22" ry="5" fill="#8b4a22"/><path d="M50 30c-3-5 3-6 0-12M62 30c-3-5 3-6 0-12M74 30c-3-5 3-6 0-12" fill="none" stroke="' + I + '" stroke-width="2" stroke-linecap="round" opacity=".3" class="steam"/>',
    other: '<g fill="' + M + '"><path d="M60 20l6 18 18 6-18 6-6 18-6-18-18-6 18-6z"/></g><g fill="' + T + '"><path d="M92 18l3 8 8 3-8 3-3 8-3-8-8-3 8-3z"/><path d="M26 56l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/></g>'
  };
  var CATS = [
    ["Traditional Rice & Grains",1,"rice","#f3e6cf"],["Cold-Pressed Mustard Oil",1,"oil","#f6e8b8"],["Pure Cow Ghee",1,"ghee","#f8ecc4"],["Bengali Spices & Masala",1,"spice","#f4dccd"],
    ["Bengali Chanachur & Mixtures",1,"mixture","#f3d9c8"],["Tea",1,"tea","#e6ecd2"],["Snacks & Nimki",1,"snack","#f6e3bd"],["Bori & Sun-Dried Foods",1,"bori","#efe3cf"],
    ["Traditional Achar & Kasundi",1,"achar","#f2d4c8"],["Traditional Jaggery & Gur",1,"jaggery","#efd9bd"],["Traditional Bengali Sweets",1,"sweet","#f7e0d8"],["Bengali Pithe & Nadu",1,"pithe","#f3e4c8"],
    ["Bengal Handicrafts & Home Decor",0,"craft","#f2d7c3"],["Bengal Artisan Crafts & Regional Specialities",0,"artisan","#ecd5cf"],["Bengali Gift Hampers & Combos",1,"hamper","#f6dccb"],
    ["Incense, Dhoop & Puja Items",0,"incense","#efe0d2"],["Bengali Tea & Beverages",1,"drink","#ecdfcb"],["Others",0,"other","#f1ead9"]
  ];
  /* ---------- revolving category rack ---------- */
  var RK = { angle: 0, vel: 0, target: null, drag: false, down: false, sx: 0, px: 0, pt: 0, sway: 0, swayV: 0, W: 150, R: 480, step: 20, grid: false, idle: 0, hover: false, suppress: false, started: false, t: 0, shades: [] };
  var REDUCED = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  function nowMs() { return (window.performance && performance.now) ? performance.now() : Date.now(); }
  function rackSize() {
    var rack = $("#rack"), w = rack.clientWidth || 360, n = CATS.length;
    RK.W = w < 480 ? 142 : (w < 820 ? 172 : 200); RK.step = 360 / n;
    RK.R = Math.round(RK.W / 2 / Math.tan(Math.PI / n) * 1.1);
    rack.style.setProperty("--W", RK.W + "px"); rack.style.setProperty("--R", RK.R + "px");
    rack.style.setProperty("--H", Math.round(RK.W * 0.75 + 62 + 26) + "px");
  }
  function centerOn(i) { var t = i * RK.step; return t + 360 * Math.round((RK.angle - t) / 360); }
  function buildChips() {
    var c = $("#chips"); c.innerHTML = ""; RK.shades = [];
    c.insertAdjacentHTML("beforeend", '<span class="rail" aria-hidden="true"></span>');
    rackSize();
    CATS.forEach(function (x, i) {
      var on = S.cats.indexOf(x[0]) > -1, hasPhoto = (window.SBH_CAT_PHOTOS || []).indexOf(x[2]) > -1;
      var b = document.createElement("button"); b.type = "button"; b.className = "cat" + (on ? " on" : ""); b.setAttribute("aria-pressed", on ? "true" : "false");
      b.style.setProperty("--tint", x[3]); b.style.setProperty("--ry", (i * RK.step) + "deg"); b.style.setProperty("--o", "0");
      b.innerHTML = '<span class="sway"><span class="hook" aria-hidden="true"></span>' +
        '<span class="face front"><span class="art' + (hasPhoto ? " photo" : "") + '">' + (hasPhoto ? '<img src="assets/cats/' + x[2] + '.jpg" alt="" decoding="async" draggable="false">' : "") +
        '<svg viewBox="0 0 120 90" aria-hidden="true">' + ART[x[2]] + '</svg><span class="tick"><svg viewBox="0 0 20 20"><path d="M4 10.5l4 4 8-9" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span></span>' +
        '<span class="nm"></span><span class="shade"></span></span>' +
        '<span class="face back" aria-hidden="true"><img src="assets/logo-256.png" alt="" draggable="false"></span></span>';
      b.querySelector(".nm").textContent = x[0];
      var im = b.querySelector(".art img"); if (im) { im.addEventListener("error", function () { im.parentNode.classList.remove("photo"); im.remove(); }); im.addEventListener("load", function () { im.classList.add("ld"); }); if (im.complete && im.naturalWidth) im.classList.add("ld"); }
      b.addEventListener("click", function () {
        if (RK.suppress) return;
        var j = S.cats.indexOf(x[0]); if (j > -1) S.cats.splice(j, 1); else S.cats.push(x[0]);
        var now = S.cats.indexOf(x[0]) > -1; b.classList.toggle("on", now); b.setAttribute("aria-pressed", now ? "true" : "false");
        if (!RK.grid) { RK.target = centerOn(i); } RK.idle = nowMs(); catCount(); applyFssai(); persist();
      });
      b.addEventListener("focus", function () { if (!RK.grid && !RK.drag) { RK.target = centerOn(i); RK.idle = nowMs(); } });
      c.appendChild(b); RK.shades.push(b);
    });
    catCount(); rackStart();
  }
  function rackStart() {
    if (RK.started) return; RK.started = true;
    var st = $("#stage"), ring = $("#chips");
    st.addEventListener("pointerdown", function (e) { if (RK.grid) return; RK.down = true; RK.drag = false; RK.sx = RK.px = e.clientX; RK.pt = nowMs(); RK.target = null; RK.idle = nowMs(); });
    st.addEventListener("pointermove", function (e) {
      if (!RK.down) return;
      var t = nowMs(), dx = e.clientX - RK.px, dt = Math.max(1, t - RK.pt);
      if (!RK.drag && Math.abs(e.clientX - RK.sx) > 6) { RK.drag = true; try { st.setPointerCapture(e.pointerId); } catch (x) {} }
      if (RK.drag) { RK.angle -= dx * 0.3; RK.vel = RK.vel * 0.6 + (-dx * 0.3 / dt * 1000) * 0.4; RK.px = e.clientX; RK.pt = t; RK.idle = t; }
    });
    function up() { if (!RK.down) return; RK.down = false; if (RK.drag) { RK.drag = false; RK.suppress = true; setTimeout(function () { RK.suppress = false; }, 60); } RK.idle = nowMs(); }
    st.addEventListener("pointerup", up); st.addEventListener("pointercancel", up);
    st.addEventListener("pointerenter", function (e) { if (e.pointerType === "mouse") RK.hover = true; });
    st.addEventListener("pointerleave", function (e) { RK.hover = false; RK.idle = nowMs(); });
    st.addEventListener("wheel", function (e) { if (RK.grid || Math.abs(e.deltaX) <= Math.abs(e.deltaY)) return; e.preventDefault(); RK.target = null; RK.angle += e.deltaX * 0.15; RK.idle = nowMs(); }, { passive: false });
    st.addEventListener("keydown", function (e) { if (e.key === "ArrowLeft") { rackStep(-1); e.preventDefault(); } else if (e.key === "ArrowRight") { rackStep(1); e.preventDefault(); } });
    $("#rkPrev").addEventListener("click", function () { rackStep(-1); });
    $("#rkNext").addEventListener("click", function () { rackStep(1); });
    $("#rkGrid").addEventListener("click", function () {
      RK.grid = !RK.grid; $("#rack").classList.toggle("grid", RK.grid); this.textContent = RK.grid ? "Rack view" : "Grid view"; this.setAttribute("aria-pressed", RK.grid ? "true" : "false");
      if (RK.grid) { ring.style.transform = ""; } RK.idle = nowMs();
    });
    window.addEventListener("resize", function () { rackSize(); });
    RK.t = nowMs(); requestAnimationFrame(rackFrame);
  }
  function rackStep(d) { var base = (RK.target != null ? RK.target : Math.round(RK.angle / RK.step) * RK.step); RK.target = base + d * RK.step; RK.idle = nowMs(); }
  function rackFrame() {
    var t = nowMs(), dt = Math.min(0.05, (t - RK.t) / 1000); RK.t = t;
    var s5 = $("#s5");
    if (!s5 || !s5.classList.contains("on") || RK.grid) { setTimeout(function () { RK.t = nowMs(); requestAnimationFrame(rackFrame); }, 250); return; }
    var drive = 0;
    if (RK.target != null) {
      var d = RK.target - RK.angle; RK.angle += d * Math.min(1, dt * 7); drive = d * 4;
      if (Math.abs(d) < 0.05) { RK.angle = RK.target; RK.target = null; RK.vel = 0; }
    } else if (!RK.drag) {
      RK.angle += RK.vel * dt; RK.vel *= Math.pow(0.04, dt); drive = RK.vel;
      if (!REDUCED && !RK.hover && !RK.down && t - RK.idle > 2600 && Math.abs(RK.vel) < 20) { RK.angle += 7 * dt; drive += 7; }
    } else { drive = RK.vel; }
    if (!REDUCED) { var acc = -45 * RK.sway - 5.5 * RK.swayV + 1.1 * Math.max(-420, Math.min(420, drive)) * -0.06 * 8; RK.swayV += acc * dt; RK.sway += RK.swayV * dt; RK.sway = Math.max(-11, Math.min(11, RK.sway)); }
    var ring = $("#chips");
    ring.style.transform = "translateZ(" + (-RK.R) + "px) rotateY(" + (-RK.angle) + "deg)";
    ring.style.setProperty("--sway", RK.sway.toFixed(2) + "deg");
    for (var i = 0; i < RK.shades.length; i++) {
      var rel = ((i * RK.step - RK.angle) % 360 + 540) % 360 - 180, f = (1 + Math.cos(rel * Math.PI / 180)) / 2;
      var o = Math.min(0.82, Math.pow(1 - f, 1.1) * 1.15).toFixed(2);
      if (RK.shades[i]._o !== o) { RK.shades[i]._o = o; RK.shades[i].style.setProperty("--o", o); }
    }
    requestAnimationFrame(rackFrame);
  }
  function catCount() { var n = S.cats.length, e = $("#catCount"); if (e) { e.textContent = n ? n + " selected" : "None selected yet"; e.classList.toggle("has", n > 0); } }
  function hasFood() { return CATS.some(function (x) { return x[1] && S.cats.indexOf(x[0]) > -1; }); }
  function applyFssai() {
    var food = hasFood(); $("#fssaiBlock").hidden = !food;
    if (!food) { S.fssai = ""; setVal("fssai_number", ""); $$("[data-fssai]").forEach(function (b) { b.classList.remove("on"); }); }
    $("#fssaiYes").hidden = !(food && S.fssai === "yes"); $("#fssaiNo").hidden = !(food && S.fssai === "no");
    $("#dz_fssai").hidden = !(food && S.fssai === "yes");
  }
  function initProducts() {
    buildChips();
    $$("[data-fssai]").forEach(function (b) { b.addEventListener("click", function () {
      S.fssai = b.dataset.fssai; $$("[data-fssai]").forEach(function (x) { x.classList.toggle("on", x === b); }); applyFssai(); persist();
    }); });
    $("#fssai_number").addEventListener("input", function () { this.value = this.value.replace(/\D/g, "").slice(0, 14); });
    $("#s5Next").addEventListener("click", function () {
      clearErr($("#s5"));
      if (!S.cats.length) { toast("Please select at least one product category.", true); return; }
      if (hasFood()) {
        if (!S.fssai) { toast("Please tell us if you have an FSSAI licence — you can choose “Not yet”.", true); return; }
        if (S.fssai === "yes" && !/^\d{14}$/.test(val("fssai_number"))) { err("fssai_number", "FSSAI number must be 14 digits"); toast("Please fix the highlighted field.", true); return; }
      }
      go(6);
    });
  }

  /* ---------- step 6: docs + submit ---------- */
  function initDocs() {
    $$("input[type=file]").forEach(function (inp) {
      inp.addEventListener("change", function () {
        var f = inp.files[0], dz = inp.closest(".dropzone"), fn = $(".fn", dz); dz.classList.remove("err");
        if (!f) { delete files[inp.dataset.doc]; dz.classList.remove("filled"); fn.textContent = ""; return; }
        if (f.size > 5 * 1024 * 1024) { toast("File is larger than 5MB.", true); inp.value = ""; return; }
        if (!/\.(jpe?g|png|pdf)$/i.test(f.name)) { toast("Only JPG, PNG or PDF files are allowed.", true); inp.value = ""; return; }
        files[inp.dataset.doc] = f; dz.classList.add("filled"); fn.textContent = "✓ " + f.name;
      });
    });
    $("#submitBtn").addEventListener("click", submit);
  }
  function buildSummary() {
    collect(); var d = S.data, s = $("#summary"); s.innerHTML = "";
    var rows = [["Brand", d.brand_name], ["Owner", d.owner_name], ["Email", d.email], ["Mobile", d.mobile], ["GST", S.gst === "yes" ? d.gstin : "Not registered / exempt"], ["PAN", d.pan_number], ["Address", [d.address_line_1, d.address_line_2, d.district, d.state, d.pin_code].filter(Boolean).join(", ")], ["Categories", S.cats.join(", ")]];
    if (hasFood()) rows.push(["FSSAI", S.fssai === "yes" ? d.fssai_number : "Not yet — will apply"]);
    rows.forEach(function (r) { var div = document.createElement("div"), a = document.createElement("span"), b = document.createElement("span"); a.textContent = r[0]; b.textContent = r[1] || "—"; div.appendChild(a); div.appendChild(b); s.appendChild(div); });
  }
  function submit() {
    var need = ["pan_document", "cheque_document"]; if (S.gst === "yes") need.push("gst_document");
    var miss = need.filter(function (k) { return !files[k]; });
    $$(".dropzone").forEach(function (d) { d.classList.remove("err"); });
    if (miss.length) { miss.forEach(function (k) { $("[data-doc=" + k + "]").closest(".dropzone").classList.add("err"); }); toast("Please upload the required documents.", true); return; }
    if (!$("#agree").checked) { toast("Please confirm the declaration to submit.", true); return; }
    collect();
    var fd = new FormData(), d = S.data;
    fd.append("token", S.token); fd.append("website", val("website"));
    Object.keys(d).forEach(function (k) { if (k !== "product_categories") fd.append(k, d[k] == null ? "" : d[k]); });
    fd.append("product_categories", JSON.stringify(S.cats));
    fd.append("mobile", normPhone(d.mobile)); fd.append("whatsapp", normPhone(d.whatsapp));
    Object.keys(files).forEach(function (k) { fd.append(k, files[k]); });
    var b = $("#submitBtn"), old = b.innerHTML; b.disabled = true; b.innerHTML = '<span class="spin"></span>Submitting';
    api("/submit", { form: fd }).then(function () {
      try { localStorage.removeItem(LS); } catch (e) {}
      go(7, false);
    }).catch(function (e) { toast(e.message || "Submission failed. Please try again.", true); b.disabled = false; b.innerHTML = old; });
  }

  /* ---------- boot ---------- */
  function boot() {
    var st = $("#state"); st.innerHTML = '<option value=""></option>' + STATES.map(function (s) { return "<option>" + s + "</option>"; }).join("");
    initWelcome(); initSignup(); initGst(); applyGst(false); initProducts(); initDocs();
    $$("[data-next]").forEach(function (b) { b.addEventListener("click", function () { go(S.step + 1); }); });
    $$("[data-back]").forEach(function (b) { b.addEventListener("click", function () { go(S.step - 1); }); });
    $$(".field input,.field textarea,.field select").forEach(function (e) {
      ["input", "change", "blur"].forEach(function (ev) { e.addEventListener(ev, function () { refresh(e); }); });
    });
    if (CFG.WHATSAPP) $("#waLink").href = "https://wa.me/" + CFG.WHATSAPP; else $("#waLink").style.display = "none";

    var token = new URLSearchParams(location.search).get("resume");
    if (token) {
      api("/draft/" + encodeURIComponent(token)).then(function (r) {
        S.token = token; restore({ token: token, gst: r.data.gst_registered, fssai: r.data.fssai_has, cats: r.data.product_categories || [], data: r.data });
        toast("Welcome back."); go(Math.min(Math.max(+r.step || 3, 3), 6), false);
        try { history.replaceState(null, "", location.pathname); } catch (e) {}
      }).catch(function () { toast("That resume link has expired or is invalid.", true); });
    } else {
      try { var o = JSON.parse(localStorage.getItem(LS) || "null"); if (o && o.data) { restore(o); } } catch (e) {}
    }
    window.addEventListener("beforeunload", function () { if (S.step < 7) persist(false); });
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", boot); else boot();
})();
