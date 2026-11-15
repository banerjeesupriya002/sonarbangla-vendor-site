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
  var CATS = [
    ["Traditional Rice & Grains",1],["Cold-Pressed Mustard Oil",1],["Pure Cow Ghee",1],["Bengali Spices & Masala",1],
    ["Bengali Chanachur & Mixtures",1],["Tea",1],["Snacks & Nimki",1],["Bori & Sun-Dried Foods",1],
    ["Traditional Achar & Kasundi",1],["Traditional Jaggery & Gur",1],["Traditional Bengali Sweets",1],["Bengali Pithe & Nadu",1],
    ["Bengal Handicrafts & Home Decor",0],["Bengal Artisan Crafts & Regional Specialities",0],["Bengali Gift Hampers & Combos",1],
    ["Incense, Dhoop & Puja Items",0],["Bengali Tea & Beverages",1],["Others",0]
  ];
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

  /* ---------- navigation ---------- */
  function go(n, push) {
    S.step = n;
    $$(".slide").forEach(function (s) { s.classList.toggle("on", +s.dataset.step === n); });
    $("#pcur").textContent = String(n).padStart(2, "0");
    $("#pfill").style.transform = "scaleX(" + Math.max(0, Math.min(1, (n - 1) / (TOTAL - 1))) + ")";
    if (n === 6) buildSummary();
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
  function buildChips() {
    var c = $("#chips"); c.innerHTML = "";
    CATS.forEach(function (x) {
      var b = document.createElement("button"); b.type = "button"; b.className = "chip" + (S.cats.indexOf(x[0]) > -1 ? " on" : ""); b.textContent = x[0];
      b.addEventListener("click", function () {
        var i = S.cats.indexOf(x[0]); if (i > -1) S.cats.splice(i, 1); else S.cats.push(x[0]);
        b.classList.toggle("on"); applyFssai(); persist();
      });
      c.appendChild(b);
    });
  }
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
    initWelcome(); initSignup(); initGst(); initProducts(); initDocs();
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
