/* ════════════════════════════════════════
   NU-SmartVote — script.js (DB-Connected)
   localStorage REMOVED — all auth/data
   goes through PHP/MariaDB backend.
   ════════════════════════════════════════ */

'use strict';

// ── Current state ─────────────────────────────────────────────────
let currentRole         = 'voter';
let currentVoterData    = null;
let currentElectionId   = null;
let votes               = {};
let clockInterval       = null;
let sessionStartTime    = Date.now();
let chartInstances      = {};

// ── Elections store (populated from get_stats.php) ─────────────────
let elections = [];

// ══════════════════════════════════════════════════════
//  HELPERS
// ══════════════════════════════════════════════════════
function getElection(id) { return elections.find(e => e.id === id); }
function getActiveElections()   { return elections.filter(e => e.status === 'active' && !e.archived); }
function getUpcomingElections() { return elections.filter(e => e.status === 'upcoming' && !e.archived); }
function getClosedElections()   { return elections.filter(e => (e.status === 'closed' || e.archived)); }

function escapeHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ══════════════════════════════════════════════════════
//  PAGE NAVIGATION
// ══════════════════════════════════════════════════════
function showAuthPage() {
    stopClock();
    document.getElementById('auth-page').classList.remove('d-none');
    document.getElementById('voter-portal').classList.add('d-none');
    document.getElementById('admin-portal').classList.add('d-none');
    showLogin();
    updateAuthBadge();
}

function showVoterPortal() {
    document.getElementById('auth-page').classList.add('d-none');
    document.getElementById('voter-portal').classList.remove('d-none');
    document.getElementById('admin-portal').classList.add('d-none');
    showVoterDashboard();
}

function showAdminPortal() {
    document.getElementById('auth-page').classList.add('d-none');
    document.getElementById('voter-portal').classList.add('d-none');
    document.getElementById('admin-portal').classList.remove('d-none');
    switchAdminTab('overview');
}

// Auth sub-sections
function showLogin()    { toggleAuthForms('login-section'); }
function showRegister() { toggleAuthForms('register-section'); }
function showForgot()   { toggleAuthForms('forgot-section'); }

function toggleAuthForms(show) {
    ['login-section','register-section','forgot-section'].forEach(id => {
        document.getElementById(id).classList.add('d-none');
    });
    document.getElementById(show).classList.remove('d-none');
}

// Voter portal sub-sections
function showVoterDashboard() {
    ['voter-dashboard','ballot-section','success-section','receipt-section'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('d-none');
    });
    const dash = document.getElementById('voter-dashboard');
    if (dash) dash.classList.remove('d-none');
    buildVoterDashboard();
}

function showBallot() {
    const elec = getElection(currentElectionId);
    if (elec && elec.voted) {
        showToast('You have already cast your vote in this election.', 'info');
        showVoterDashboard();
        return;
    }

    ['voter-dashboard','ballot-section','success-section','receipt-section'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('d-none');
    });
    const ballot = document.getElementById('ballot-section');
    if (ballot) ballot.classList.remove('d-none');
    buildBallot();
}

function showSuccess() {
    ['voter-dashboard','ballot-section','success-section','receipt-section'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('d-none');
    });
    document.getElementById('success-section').classList.remove('d-none');
}

async function showReceipt(elecId) {
    ['voter-dashboard','ballot-section','success-section','receipt-section'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.add('d-none');
    });
    const receiptSec = document.getElementById('receipt-section');
    if (receiptSec) receiptSec.classList.remove('d-none');
    
    const content = document.getElementById('receipt-content');
    if (content) {
        content.innerHTML = '<div style="text-align:center; padding:2rem;"><i class="bi bi-arrow-repeat spin me-2"></i>Loading receipt...</div>';
        
        try {
            const res = await fetch(`get_stats.php?section=receipt&election_id=${encodeURIComponent(elecId)}`);
            const data = await res.json();
            
            if (data.success && data.receipt) {
                if (data.receipt.length === 0) {
                    content.innerHTML = '<div style="text-align:center; color:var(--danger);">No votes found for this election.</div>';
                    return;
                }
                content.innerHTML = data.receipt.map(r => `
                    <div style="border-bottom:1px solid var(--border-light); padding:1rem 0; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:0.75rem; color:var(--text-light); text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;">${escapeHtml(r.position)}</div>
                            <div style="font-weight:600; color:var(--text-dark); font-size:1.1rem;">${escapeHtml(r.candidate)}</div>
                        </div>
                        <div style="text-align:right;">
                            <span style="background:var(--bg-light); padding:4px 10px; border-radius:6px; font-size:0.75rem; color:var(--text-mid); border:1px solid var(--border-light); font-weight:600;">${escapeHtml(r.party)}</span>
                        </div>
                    </div>
                `).join('');
            } else {
                content.innerHTML = `<div style="text-align:center; color:var(--danger);">${escapeHtml(data.error || 'Failed to load receipt.')}</div>`;
            }
        } catch (err) {
            content.innerHTML = '<div style="text-align:center; color:var(--danger);">Network error. Could not load receipt.</div>';
        }
    }
}

// ══════════════════════════════════════════════════════
//  AUTH BADGE
// ══════════════════════════════════════════════════════
function updateAuthBadge() {
    const active = getActiveElections();
    const badge  = document.getElementById('auth-election-name-badge');
    if (active.length > 0) {
        badge.textContent = active.length === 1
            ? `${active[0].name} is now open`
            : `${active.length} elections are currently open`;
    } else {
        badge.textContent = 'No active election at this time';
    }
}

// ══════════════════════════════════════════════════════
//  ROLE TOGGLE
// ══════════════════════════════════════════════════════
function setRole(role) {
    currentRole = role;
    const vTab      = document.getElementById('voter-tab');
    const aTab      = document.getElementById('admin-tab');
    const slider    = document.getElementById('role-slider');
    const voterForm = document.getElementById('voter-login-form');
    const adminForm = document.getElementById('admin-login-form');

    if (role === 'admin') {
        aTab.classList.add('active');    vTab.classList.remove('active');
        slider.classList.add('admin-mode');
        voterForm?.classList.add('d-none');
        adminForm?.classList.remove('d-none');
    } else {
        vTab.classList.add('active');    aTab.classList.remove('active');
        slider.classList.remove('admin-mode');
        voterForm?.classList.remove('d-none');
        adminForm?.classList.add('d-none');
    }
}

// ══════════════════════════════════════════════════════
//  PASSWORD TOGGLE
// ══════════════════════════════════════════════════════
function togglePass(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') { input.type = 'text';     icon.className = 'bi bi-eye-slash'; }
    else                           { input.type = 'password'; icon.className = 'bi bi-eye'; }
}

// ══════════════════════════════════════════════════════
//  EMAIL GENERATOR + FULL NAME BUILDER
// ══════════════════════════════════════════════════════
function generateEmail() {
    const f = document.getElementById('regFirst').value.trim();
    const m = document.getElementById('regMiddle').value.trim();
    const l = document.getElementById('regLast').value.trim().replace(/\s+/g,'');

    const fL = f.toLowerCase(), mL = m.toLowerCase(), lL = l.toLowerCase();
    const emailField = document.getElementById('regEmail');
    if (f && l) {
        emailField.value = `${fL[0]}${mL ? mL[0] : ''}${lL}@nu-dasma.edu.ph`;
    } else {
        emailField.value = '';
    }

    const fullNameField = document.getElementById('regFullName');
    if (fullNameField) {
        const parts = [f, m ? m + '.' : '', l].filter(Boolean);
        fullNameField.value = parts.join(' ');
    }
}

// ══════════════════════════════════════════════════════
//  REGISTER — CLIENT-SIDE VALIDATION ONLY
//  NO localStorage. DB duplicate-check is done by PHP.
// ══════════════════════════════════════════════════════
function validateRegister(event) {
    const id             = document.getElementById('regID').value.trim();
    const first          = document.getElementById('regFirst').value.trim();
    const last           = document.getElementById('regLast').value.trim();
    const email          = document.getElementById('regEmail').value.trim();
    const recoveryEmail  = document.getElementById('regRecovery')?.value.trim() || '';
    const dept           = document.getElementById('regCourse');
    const year           = document.getElementById('regYear');
    const pass           = document.getElementById('regPass').value;
    const confirm        = document.getElementById('regConfirm').value;

    if (!id || !first || !last) {
        showToast('Please fill in your Student ID and name.','error');
        event.preventDefault(); return false;
    }
    if (!email || !email.includes('@')) {
        showToast('Please enter a valid NU email address.','error');
        event.preventDefault(); return false;
    }
    if (!recoveryEmail || !recoveryEmail.includes('@gmail.')) {
        showToast('Please enter a valid personal Gmail address for recovery.','error');
        event.preventDefault(); return false;
    }
    if (!dept.value || dept.selectedIndex === 0) {
        showToast('Please select your department.','error');
        event.preventDefault(); return false;
    }
    if (!year.value || year.selectedIndex === 0) {
        showToast('Please select your year level.','error');
        event.preventDefault(); return false;
    }
    if (pass.length < 6) {
        showToast('Password must be at least 6 characters.','error');
        event.preventDefault(); return false;
    }
    if (pass !== confirm) {
        showToast('Passwords do not match.','error');
        event.preventDefault(); return false;
    }

    // Ensure hidden full-name field is populated before POST
    generateEmail();

    // Return true → form POSTs to register.php for MySQL storage
    return true;
}

// Keep alias for stray calls
function registerUser() {
    const form = document.getElementById('register-form');
    if (form) form.requestSubmit();
}

//  FORGOT PASSWORD
//  Submits the personal Gmail to forgot_password.php via POST.
// ══════════════════════════════════════════════════════
function processReset() {
    const email = document.getElementById('forgotEmail').value.trim();
    if (!email) {
        showToast('Please enter your personal Gmail address.','error');
        return;
    }
    if (!email.toLowerCase().includes('@gmail.')) {
        showToast('Please enter a valid Gmail address (e.g. yourname@gmail.com).','error');
        return;
    }

    // Build a temporary form and POST to forgot_password.php
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'forgot_password.php';
    const inp = document.createElement('input');
    inp.type  = 'hidden';
    inp.name  = 'recovery_email';
    inp.value = email;
    form.appendChild(inp);
    document.body.appendChild(form);
    form.submit();
}

// ══════════════════════════════════════════════════════
//  URL PARAM HANDLING (after PHP redirects)
//  voter_login.php → index.html?login=success&name=...
// ══════════════════════════════════════════════════════
function handleUrlParams() {
    const params = new URLSearchParams(window.location.search);

    // Preserve admin tab state if redirect specifies it
    if (params.get('role') === 'admin') {
        setRole('admin');
    }

    // Voter login success
    if (params.get('login') === 'success') {
        const name = params.get('name') || 'Voter';
        const sid  = params.get('sid')  || '';
        currentVoterData = { first: name.split(' ')[0], id: sid, name };

        const nameEl = document.getElementById('portal-voter-name');
        const idEl   = document.getElementById('voter-id-display');
        if (nameEl) nameEl.textContent = currentVoterData.first;
        if (idEl)   idEl.textContent   = sid ? 'Student ID: ' + sid : '';

        showToast(`Welcome, ${currentVoterData.first}!`,'success');
        setTimeout(showVoterPortal, 600);
        cleanUrl();
        return true;
    }

    // Admin login success (handled by admin_login.php redirect)
    if (params.get('admin') === 'success') {
        const name = params.get('name') || 'Admin';
        showToast(`Welcome, ${name}!`,'success');
        setTimeout(showAdminPortal, 600);
        cleanUrl();
        return true;
    }

    // Error params
    const error = params.get('error');
    if (error) {
        const msgs = {
            wrong_password: 'Incorrect password. Please try again.',
            not_found:      'No account found with those credentials.',
            reset_failed:   'Password reset failed. Please try again.',
            invalid_reset_email: 'Please enter a valid Gmail address.',
            phpmailer_missing: 'System Error: PHPMailer is not installed. Contact the administrator.',
        };
        showToast(msgs[error] || 'An error occurred.','error');
        cleanUrl();
    }

    // Password reset sent
    if (params.get('reset') === 'sent') {
        showToast('If that Gmail is registered, a code has been sent.','info');
        cleanUrl();
    }

    return false;
}

function cleanUrl() {
    window.history.replaceState({}, document.title, window.location.pathname);
}

// ══════════════════════════════════════════════════════
//  VOTER DASHBOARD
// ══════════════════════════════════════════════════════
function buildVoterDashboard() {
    const active = getActiveElections();

    if (active.length === 0) {
        currentElectionId = null;
        document.getElementById('voter-hero-title').textContent = 'No Active Elections';
        document.getElementById('voter-hero-sub').textContent   = 'There are no elections open for voting at this time.';
        const voteBtn = document.getElementById('voter-vote-btn');
        if (voteBtn) voteBtn.style.display = 'none';
        document.getElementById('voter-period-date').textContent  = '—';
        document.getElementById('voter-period-close').textContent = 'No active election.';
        document.getElementById('portal-election-title').textContent = 'No Active Elections';
        document.getElementById('election-picker-section').classList.add('d-none');
        document.getElementById('voter-main-content').classList.remove('d-none');
        document.getElementById('other-elections-section').classList.add('d-none');
        return;
    }

    if (active.length > 1 && !currentElectionId) {
        document.getElementById('election-picker-section').classList.remove('d-none');
        document.getElementById('voter-main-content').classList.add('d-none');
        buildElectionPicker(active);
        return;
    }

    document.getElementById('election-picker-section').classList.add('d-none');
    document.getElementById('voter-main-content').classList.remove('d-none');

    if (!currentElectionId) currentElectionId = active[0].id;
    const elec = getElection(currentElectionId);
    if (!elec) return;

    document.getElementById('voter-hero-title').textContent     = elec.name;
    document.getElementById('voter-hero-sub').textContent       = 'Your Voice, Your Choice — Cast your ballot securely.';
    updateVoteButtonState(elec);
    document.getElementById('voter-period-date').textContent    = formatDateRange(elec.startDate, elec.endDate);
    document.getElementById('voter-period-close').textContent   = `Voting closes on ${formatDate(elec.endDate)}`;
    document.getElementById('portal-election-title').textContent = elec.name;

    const statusBadge = document.getElementById('voter-status-badge');
    if (statusBadge) {
        if (elec.voted) {
            statusBadge.className = 'voter-status-badge voted';
            statusBadge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Voted';
            statusBadge.style.background = 'rgba(16, 185, 129, 0.1)';
            statusBadge.style.color = '#10b981';
            statusBadge.style.border = '1px solid rgba(16, 185, 129, 0.2)';
        } else {
            statusBadge.className = 'voter-status-badge';
            statusBadge.innerHTML = '<i class="bi bi-check-circle-fill"></i> Eligible to Vote';
            statusBadge.style.background = 'rgba(10, 88, 202, 0.1)';
            statusBadge.style.color = '#0a58ca';
            statusBadge.style.border = 'none';
        }
    }

    const others = active.filter(e => e.id !== currentElectionId);
    if (others.length > 0) {
        const sec = document.getElementById('other-elections-section');
        const lst = document.getElementById('other-elections-list');
        sec.classList.remove('d-none');
        lst.innerHTML = others.map(e => `
            <div class="voter-election-card mb-2" onclick="switchVoterElection('${e.id}')">
                <div class="d-flex align-items-center gap-3">
                    <div class="voter-hero-icon" style="width:36px;height:36px;font-size:1rem;background:var(--nu-blue-light);color:var(--nu-blue);border-radius:9px;"><i class="bi bi-ballot-fill"></i></div>
                    <div>
                        <div style="font-size:.88rem;font-weight:700;color:var(--text-dark);">${escapeHtml(e.name)}</div>
                        <div style="font-size:.75rem;color:var(--text-mid);">${formatDateRange(e.startDate, e.endDate)}</div>
                    </div>
                    <button class="btn-primary-sm ms-auto">Vote Here</button>
                </div>
            </div>`).join('');
    } else {
        document.getElementById('other-elections-section').classList.add('d-none');
    }
}

function buildElectionPicker(active) {
    const container = document.getElementById('voter-election-cards');
    container.innerHTML = active.map(e => `
        <div class="voter-election-card" onclick="switchVoterElection('${e.id}')">
            <div class="election-status-badge active mb-2"><i class="bi bi-activity"></i> Active</div>
            <div style="font-size:1rem;font-weight:700;color:var(--text-dark);margin-bottom:6px;">${escapeHtml(e.name)}</div>
            <div style="font-size:.78rem;color:var(--text-mid);margin-bottom:1rem;">${formatDateRange(e.startDate, e.endDate)}</div>
            <button class="btn-primary-sm w-100"><i class="bi bi-check2-square me-1"></i>Vote in this Election</button>
        </div>`).join('');
}

function switchVoterElection(elecId) {
    currentElectionId = elecId;
    document.getElementById('election-picker-section').classList.add('d-none');
    document.getElementById('voter-main-content').classList.remove('d-none');
    buildVoterDashboard();
}

function updateVoteButtonState(elec) {
    const voteBtn = document.getElementById('voter-vote-btn');
    if (!voteBtn) return;
    if (elec && elec.voted) {
        voteBtn.disabled = false;
        voteBtn.innerHTML = '<i class="bi bi-receipt me-2"></i>View Receipt';
        voteBtn.className = 'btn-outline-sm';
        voteBtn.style.cursor = 'pointer';
        voteBtn.style.width = 'fit-content';
        voteBtn.style.padding = '0.75rem 1.5rem';
        voteBtn.style.color = 'white';
        voteBtn.style.borderColor = 'rgba(255,255,255,0.5)';
        voteBtn.onclick = () => showReceipt(elec.id);
    } else {
        voteBtn.disabled = false;
        voteBtn.innerHTML = '<i class="bi bi-check2-square me-2"></i>Vote Now';
        voteBtn.className = 'btn-vote-now';
        voteBtn.style.cursor = 'pointer';
        voteBtn.style.width = '';
        voteBtn.style.padding = '';
        voteBtn.style.color = '';
        voteBtn.style.borderColor = '';
        voteBtn.onclick = showBallot;
    }
}

// ══════════════════════════════════════════════════════
//  BALLOT
// ══════════════════════════════════════════════════════
const AVATAR_COLORS = [
    ['#3b82f6','#eff6ff'], ['#8b5cf6','#f5f3ff'], ['#ec4899','#fdf2f8'],
    ['#10b981','#ecfdf5'], ['#f59e0b','#fffbeb'], ['#ef4444','#fef2f2']
];

function getAllPositions(elec) {
    const fromCandidates = [...new Set(elec.partyLists.flatMap(p => p.candidates.map(c => c.position)))];
    const ordered = elec.customPositions.filter(p => fromCandidates.includes(p));
    const extras  = fromCandidates.filter(p => !elec.customPositions.includes(p));
    return [...ordered, ...extras];
}

function getPositionKey(pos) {
    return pos.toLowerCase().replace(/\s+/g,'_').replace(/[^a-z0-9_]/g,'');
}

function getCandidatesByPosition(elec, pos) {
    return elec.partyLists.flatMap(p =>
        p.candidates.filter(c => c.position === pos).map(c => ({ ...c, party: p.name }))
    );
}

function buildBallot() {
    const elec = getElection(currentElectionId);
    if (!elec) { showVoterDashboard(); return; }

    document.getElementById('ballot-election-title').textContent = `Official Ballot · ${elec.name}`;
    document.querySelector('.ballot-main-title').textContent = elec.name;

    const container = document.getElementById('ballot-positions-container');
    container.innerHTML = '';
    votes = {};

    const positions = getAllPositions(elec);
    positions.forEach(p => { votes[getPositionKey(p)] = []; });

    positions.forEach((pos, idx) => {
        const key        = getPositionKey(pos);
        const candidates = getCandidatesByPosition(elec, pos);
        if (candidates.length === 0) return;
        const abstainId  = 'ABSTAIN__' + pos;
        
        // Automatically determine max votes by finding the party with the most candidates for this position
        let maxVotes = 1;
        elec.partyLists.forEach(p => {
            const count = p.candidates.filter(c => c.position === pos).length;
            if (count > maxVotes) maxVotes = count;
        });

        const block = document.createElement('div');
        block.className = 'position-block';
        block.innerHTML = `
            <div class="position-label">
                <span class="pos-num">${String(idx+1).padStart(2,'0')}</span>
                <div>
                    <div class="pos-title">${escapeHtml(pos)}</div>
                    <div class="pos-sub">Select ${maxVotes > 1 ? 'up to ' + maxVotes + ' candidates' : 'one candidate'}</div>
                </div>
                <i class="bi bi-circle pos-check" id="chk-${key}"></i>
            </div>
            <div class="candidates-grid">
                ${candidates.map((c, ci) => {
                    const [bg, bg2] = AVATAR_COLORS[ci % AVATAR_COLORS.length];
                    const initials  = c.name.split(' ').map(n => n[0]).join('').slice(0,2);
                    return `
                    <div class="candidate-card" data-candidate-id="${c.id}" onclick="selectCandidate(this,'${key}', ${maxVotes})">
                        <div class="cand-avatar" style="background:${bg2};color:${bg};">${initials}</div>
                        <div>
                            <div class="cand-name">${escapeHtml(c.name)}</div>
                            <div class="cand-party">${escapeHtml(c.party)}</div>
                        </div>
                        <div class="cand-radio"><i class="bi bi-circle"></i></div>
                    </div>`;
                }).join('')}
                <div class="candidate-card" data-candidate-id="${abstainId}" onclick="selectCandidate(this,'${key}', ${maxVotes})">
                    <div class="cand-avatar" style="background:#f1f5f9;color:#64748b;">AB</div>
                    <div>
                        <div class="cand-name" style="color:var(--text-mid); font-style:italic;">Abstain</div>
                        <div class="cand-party">—</div>
                    </div>
                    <div class="cand-radio"><i class="bi bi-circle"></i></div>
                </div>
            </div>`;
        container.appendChild(block);
    });

    updateBallotProgress(elec);
}

function selectCandidate(cardEl, position, maxVotes) {
    const candId = cardEl.dataset.candidateId;
    if (!votes[position]) votes[position] = [];

    if (candId.startsWith('ABSTAIN__')) {
        votes[position] = [candId];
    } else {
        votes[position] = votes[position].filter(id => !id.startsWith('ABSTAIN__'));
        
        const idx = votes[position].indexOf(candId);
        if (idx > -1) {
            votes[position].splice(idx, 1);
        } else {
            if (votes[position].length < maxVotes) {
                votes[position].push(candId);
            } else if (maxVotes === 1) {
                votes[position] = [candId];
            } else {
                showToast(`You can only select up to ${maxVotes} candidates for this position.`, 'error');
                return;
            }
        }
    }

    const grid = cardEl.closest('.candidates-grid');
    grid.querySelectorAll('.candidate-card').forEach(c => {
        if (votes[position].includes(c.dataset.candidateId)) {
            c.classList.add('selected');
            c.querySelector('.cand-radio i').className = 'bi bi-check-circle-fill';
        } else {
            c.classList.remove('selected');
            c.querySelector('.cand-radio i').className = 'bi bi-circle';
        }
    });

    const chk = document.getElementById('chk-' + position);
    if (chk) {
        if (votes[position].length > 0) {
            chk.classList.add('done');
            chk.className = 'bi bi-check-circle-fill pos-check done';
        } else {
            chk.classList.remove('done');
            chk.className = 'bi bi-circle pos-check';
        }
    }
    const elec = getElection(currentElectionId);
    updateBallotProgress(elec);
}

function updateBallotProgress(elec) {
    if (!elec) return;
    const allKeys = Object.keys(votes);
    const filled  = allKeys.filter(k => Array.isArray(votes[k]) && votes[k].length > 0).length;
    const total   = allKeys.length;
    if (total === 0) return;

    document.getElementById('ballot-progress').style.width = (filled / total * 100) + '%';
    document.getElementById('ballot-progress-label').textContent = `${filled} / ${total} selected`;

    const submitBtn  = document.getElementById('submit-btn');
    const submitNote = document.getElementById('submit-note');
    if (filled === total) {
        submitBtn.disabled = false;
        submitNote.textContent = '✓ All positions selected. You may now submit your vote.';
        submitNote.classList.add('ready');
    } else {
        submitBtn.disabled = true;
        const r = total - filled;
        submitNote.innerHTML = `<i class="bi bi-exclamation-triangle me-2"></i>${r} position${r > 1 ? 's' : ''} still need${r === 1 ? 's' : ''} a selection.`;
        submitNote.classList.remove('ready');
    }
}

function showVoteConfirmModal() {
    const elec = getElection(currentElectionId);
    if (!elec) return;

    const allKeys = Object.keys(votes);
    if (allKeys.some(k => !Array.isArray(votes[k]) || votes[k].length === 0)) {
        showToast('Please vote in all positions first.','error');
        return;
    }

    const listContainer = document.getElementById('vote-confirm-list');
    let html = '';
    // Generate the receipt summary visually linking positions to the chosen candidate
    const positions = getAllPositions(elec);
    positions.forEach(pos => {
        const key = getPositionKey(pos);
        const candIds = votes[key] || [];
        if (candIds.length === 0) return;

        candIds.forEach(candId => {
            let candName = 'Unknown';
            let partyName = 'Unknown';
        
            if (candId.startsWith('ABSTAIN__')) {
                candName = 'Abstain';
                partyName = '—';
            } else {
                elec.partyLists.forEach(p => {
                    const c = p.candidates.find(cand => cand.id === candId);
                    if (c) {
                        candName = c.name;
                        partyName = p.name;
                    }
                });
            }

            html += `
            <div style="border-bottom:1px solid var(--border-light); padding:0.75rem 0; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <div style="font-size:0.75rem; color:var(--text-light); text-transform:uppercase; letter-spacing:1px; margin-bottom:2px;">${escapeHtml(pos)}</div>
                    <div style="font-weight:600; color:var(--text-dark); font-size:1rem;">${escapeHtml(candName)}</div>
                </div>
                <div style="text-align:right;">
                    <span style="background:var(--bg-light); padding:4px 8px; border-radius:6px; font-size:0.7rem; color:var(--text-mid); border:1px solid var(--border-light); font-weight:600;">${escapeHtml(partyName)}</span>
                </div>
            </div>`;
        });
    });

    listContainer.innerHTML = html;
    document.getElementById('vote-confirm-modal-overlay').classList.remove('d-none');
}

function closeVoteConfirmModal() {
    document.getElementById('vote-confirm-modal-overlay').classList.add('d-none');
}

async function submitVote() {
    const elec = getElection(currentElectionId);
    if (!elec) return;

    const submitBtn = document.getElementById('modal-submit-btn');
    const cancelBtn = document.getElementById('modal-cancel-btn');
    submitBtn.disabled = true;
    cancelBtn.disabled = true;
    submitBtn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>Submitting...';

    try {
        const response = await fetch('submit_vote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                election_id:   currentElectionId,
                candidate_ids: Object.values(votes).flat().filter(Boolean)
            })
        });
        
        const rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (parseErr) {
            throw new Error("PHP Error: " + rawText.replace(/(<([^>]+)>)/gi, "").substring(0, 100));
        }
        
        if (result.success) {
            const now = new Date();
            const timeStr = now.toLocaleDateString('en-PH', { year:'numeric', month:'long', day:'numeric' })
                          + ' · ' + now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });

            document.getElementById('receipt-election-name').textContent = elec.name;
            const receiptTime = document.getElementById('receipt-time');
            if (receiptTime) receiptTime.querySelector('span:last-child').textContent = timeStr;

            elec.voted = true;

            closeVoteConfirmModal();

            showSuccess();
            
            // Overwrite the "#ballot" history state with "#success" to prevent the back button from returning to the ballot
            if (typeof history.replaceState === 'function') {
                history.replaceState({ page: 'success' }, '', '#success');
            }
            
            showToast('Vote submitted successfully!','success');
        } else {
            showToast(result.error || 'An error occurred.','error');
            submitBtn.disabled = false;
            cancelBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-send-check-fill me-1"></i>Confirm & Submit';
        }
    } catch (err) {
        console.error('Vote submission error:', err);
        showToast(err.message || 'A network error occurred.', 'error');
        submitBtn.disabled = false;
        cancelBtn.disabled = false;
        submitBtn.innerHTML = '<i class="bi bi-send-check-fill me-1"></i>Confirm & Submit';
    }
}

// ══════════════════════════════════════════════════════
//  ADMIN TABS
// ══════════════════════════════════════════════════════
const ADMIN_TABS = ['overview','elections','candidates','results','archive','voters'];

function switchAdminTab(tab) {
    ADMIN_TABS.forEach(t => {
        const tabEl = document.getElementById(`tab-${t}`);
        const navEl = document.getElementById(`nav-${t}`);
        if (tabEl) tabEl.classList.add('d-none');
        if (navEl) navEl.classList.remove('active');
    });
    const active = document.getElementById(`tab-${tab}`);
    const navActive = document.getElementById(`nav-${tab}`);
    if (active)    active.classList.remove('d-none');
    if (navActive) navActive.classList.add('active');

    if (tab === 'overview')   renderOverview();
    if (tab === 'elections')  renderElectionsList();
    if (tab === 'candidates') { populateCandElectionFilter(); renderCandidatesTab(); }
    if (tab === 'results')    { populateResultsFilter(); renderResults(); startClock(); }
    if (tab === 'archive')    renderArchive();
    if (tab === 'voters')     fetchVoterData();
    if (tab !== 'results')    stopClock();
}

// ══════════════════════════════════════════════════════
//  OVERVIEW TAB — live data from get_stats.php
// ══════════════════════════════════════════════════════
async function renderOverview() {
    document.getElementById('overview-stats').innerHTML =
        '<div style="grid-column:1/-1;text-align:center;padding:2rem;color:var(--text-mid);">Loading statistics from database…</div>';
    const grid = document.getElementById('overview-elections-grid');
    grid.innerHTML = '';

    try {
        const response = await fetch('get_stats.php?section=all');
        const data     = await response.json();

        if (!data.success) throw new Error('API error');

        const stats = data.summary;
        document.getElementById('overview-stats').innerHTML = `
            <div class="stat-card stat-blue">
                <div class="stat-icon-wrap stat-icon-blue"><i class="bi bi-activity"></i></div>
                <div class="stat-label">Active Elections</div>
                <div class="stat-value">${stats.active_elections}</div>
                <div class="stat-sub">Open for voting</div>
            </div>
            <div class="stat-card stat-gold">
                <div class="stat-icon-wrap stat-icon-gold"><i class="bi bi-hourglass-split"></i></div>
                <div class="stat-label">Upcoming</div>
                <div class="stat-value">${stats.upcoming_elections}</div>
                <div class="stat-sub">Scheduled elections</div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon-wrap stat-icon-green"><i class="bi bi-people-fill"></i></div>
                <div class="stat-label">Registered Voters</div>
                <div class="stat-value">${stats.total_registered_users}</div>
                <div class="stat-sub">${stats.unique_voters} have voted · ${stats.turnout_pct}% turnout</div>
            </div>
            <div class="stat-card stat-purple">
                <div class="stat-icon-wrap stat-icon-purple"><i class="bi bi-archive-fill"></i></div>
                <div class="stat-label">Archived</div>
                <div class="stat-value">${stats.closed_elections}</div>
                <div class="stat-sub">Past elections</div>
            </div>`;

        const activeElecs = (data.elections || []).filter(e => e.status === 'active');
        if (activeElecs.length === 0) {
            grid.innerHTML = `<div class="admin-empty-state" style="grid-column:1/-1;"><i class="bi bi-calendar-x"></i>No active elections found in the database.</div>`;
            return;
        }
        grid.innerHTML = activeElecs.map(e => `
            <div class="overview-election-card">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="election-status-badge ${e.status}"><i class="bi bi-circle-fill"></i>${e.status.charAt(0).toUpperCase() + e.status.slice(1)}</span>
                </div>
                <div style="font-size:.95rem;font-weight:700;color:var(--text-dark);margin-bottom:4px;">${escapeHtml(e.name)}</div>
                <div style="font-size:.75rem;color:var(--text-mid);margin-bottom:10px;">Eligible Voters: ${e.eligible_voters}</div>
                <div class="d-flex align-items-center gap-2" style="font-size:.78rem;color:var(--text-mid);">
                    <i class="bi bi-people-fill" style="color:var(--nu-blue);"></i>
                    ${e.votes_cast} votes · ${e.turnout_pct}% turnout
                </div>
            </div>`).join('');
    } catch (err) {
        console.error('Error fetching stats from database:', err);
        document.getElementById('overview-stats').innerHTML =
            '<div class="admin-empty-state" style="grid-column:1/-1;"><i class="bi bi-exclamation-triangle"></i> Error loading database statistics. Check if your XAMPP server is running.</div>';
    }
}

// ══════════════════════════════════════════════════════
//  VOTER TRACKER — fetch from get_stats.php
// ══════════════════════════════════════════════════════
async function fetchVoterData() {
    const container = document.getElementById('voter-tracker-table-wrap');
    const summary   = document.getElementById('voter-tracker-summary');
    if (!container) return;

    container.innerHTML = `
        <div class="tracker-loading">
            <i class="bi bi-arrow-repeat spin me-2"></i>Loading voter data from database…
        </div>`;

    const elecFilter = document.getElementById('tracker-election-filter');
    const elecId     = elecFilter ? elecFilter.value : '';
    const url        = elecId
        ? `get_stats.php?section=voter_tracker&election_id=${encodeURIComponent(elecId)}`
        : 'get_stats.php?section=voter_tracker';

    try {
        const response = await fetch(url);
        const data     = await response.json();

        if (!data.success || !Array.isArray(data.voter_tracker)) throw new Error('Bad response');

        const tracker = data.voter_tracker;
        const voted   = tracker.filter(v => v.has_voted).length;
        const total   = tracker.length;
        const pct     = total > 0 ? Math.round((voted / total) * 100) : 0;

        if (summary) {
            summary.innerHTML = `
                <span class="tracker-stat"><i class="bi bi-people-fill me-1" style="color:var(--nu-blue);"></i>${total} registered</span>
                <span class="tracker-stat voted"><i class="bi bi-check-circle-fill me-1"></i>${voted} voted</span>
                <span class="tracker-stat not-voted"><i class="bi bi-x-circle-fill me-1"></i>${total - voted} not voted</span>
                <span class="tracker-stat pct"><i class="bi bi-graph-up me-1"></i>${pct}% turnout</span>`;
        }

        if (tracker.length === 0) {
            container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-people"></i>No registered voters found.</div>`;
            return;
        }

        // Search input value
        const search = (document.getElementById('tracker-search')?.value || '').toLowerCase();
        const filter = document.getElementById('tracker-status-filter')?.value || 'all';

        const filtered = tracker.filter(v => {
            const matchSearch = !search
                || v.name.toLowerCase().includes(search)
                || v.student_id.toLowerCase().includes(search)
                || (v.department || '').toLowerCase().includes(search);
            const matchFilter = filter === 'all'
                || (filter === 'voted' && v.has_voted)
                || (filter === 'not_voted' && !v.has_voted);
            return matchSearch && matchFilter;
        });

        if (filtered.length === 0) {
            container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-search"></i>No results match your filter.</div>`;
            return;
        }

        container.innerHTML = `
            <table class="tracker-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Student ID</th>
                        <th>Department</th>
                        <th>Year</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    ${filtered.map((v, i) => `
                    <tr>
                        <td class="tracker-num">${i + 1}</td>
                        <td class="tracker-name">${escapeHtml(v.name)}</td>
                        <td class="tracker-sid">${escapeHtml(v.student_id)}</td>
                        <td class="tracker-dept">${escapeHtml(v.department || '—')}</td>
                        <td class="tracker-year">${v.year_level ? escapeHtml(v.year_level) + (v.year_level==='1'?'st':v.year_level==='2'?'nd':v.year_level==='3'?'rd':'th') + ' Yr' : '—'}</td>
                        <td>
                            <span class="vote-status-badge ${v.has_voted ? 'voted' : 'not-voted'}">
                                <i class="bi ${v.has_voted ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>
                                ${v.has_voted ? 'Voted' : 'Not Voted'}
                            </span>
                        </td>
                    </tr>`).join('')}
                </tbody>
            </table>`;
    } catch (err) {
        console.error('Voter tracker error:', err);
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-exclamation-triangle"></i>Error loading voter data. Check server connection.</div>`;
    }
}

// ══════════════════════════════════════════════════════
//  ELECTIONS TAB
// ══════════════════════════════════════════════════════
function renderElectionsList() {
    const container = document.getElementById('elections-list');
    const visible   = elections.filter(e => !e.archived);

    if (visible.length === 0) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-calendar-x"></i>No elections yet. Click "New Election" to create one.</div>`;
        return;
    }
    container.innerHTML = visible.map(e => `
        <div class="election-list-item">
            <div class="election-list-icon"><i class="bi bi-calendar-event-fill"></i></div>
            <div class="election-list-info">
                <div class="election-list-name">${escapeHtml(e.name)}</div>
                <div class="election-list-dates">${formatDateRange(e.startDate, e.endDate)} · ${e.eligibleVoters} eligible voters</div>
            </div>
            <span class="election-status-badge ${e.status}"><i class="bi bi-circle-fill"></i>${e.status.charAt(0).toUpperCase()+e.status.slice(1)}</span>
            <select class="election-status-select" onchange="changeElectionStatus('${e.id}', this.value)">
                <option value="active"   ${e.status==='active'   ? 'selected' : ''}>Active</option>
                <option value="upcoming" ${e.status==='upcoming' ? 'selected' : ''}>Upcoming</option>
                <option value="closed"   ${e.status==='closed'   ? 'selected' : ''}>Closed</option>
            </select>
            <div class="election-list-actions">
                <button class="btn-icon-info"   onclick="showEditElectionModal('${e.id}')" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                <button class="btn-icon-info"   onclick="archiveElection('${e.id}')"  title="Archive"><i class="bi bi-archive"></i></button>
                <button class="btn-icon-danger" onclick="deleteElection('${e.id}')"   title="Delete"><i class="bi bi-trash-fill"></i></button>
            </div>
        </div>`).join('');
}

async function changeElectionStatus(elecId, newStatus) {
    const elec = getElection(elecId);
    if (!elec) return;

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_status', election_id: elecId, status: newStatus })
        });
        const data = await res.json();
        if (data.success) {
            elec.status = newStatus;
            renderElectionsList();
            renderOverview();
            showToast(`Election status updated to "${newStatus}".`,'info');
            updateAuthBadge();
        } else {
            showToast('Error updating status.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

async function archiveElection(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    if (!confirm(`Archive "${elec.name}"? It will be moved to the Archive tab.`)) return;
    
    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_status', election_id: elecId, status: 'archived' })
        });
        const data = await res.json();
        if (data.success) {
            elec.archived = true;
            elec.status   = 'archived';
            renderElectionsList();
            renderOverview();
            showToast(`"${elec.name}" archived.`,'info');
            updateAuthBadge();
        } else {
            showToast('Error archiving election.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

async function deleteElection(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    if (!confirm(`Permanently delete "${elec.name}"? This cannot be undone.`)) return;
    
    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_election', election_id: elecId })
        });
        const data = await res.json();
        if (data.success) {
            const idx = elections.findIndex(e => e.id === elecId);
            if (idx !== -1) elections.splice(idx, 1);
            renderElectionsList();
            renderOverview();
            showToast('Election deleted.','info');
            updateAuthBadge();
        } else {
            showToast('Error deleting election.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ── Add Election Modal ──────────────────
function showAddElectionModal() {
    document.getElementById('newElectionName').value   = '';
    document.getElementById('newElectionStart').value  = '';
    document.getElementById('newElectionEnd').value    = '';
    document.getElementById('newElectionVoters').value = '';
    document.getElementById('newElectionStatus').value = 'active';
    document.getElementById('election-modal-overlay').classList.remove('d-none');
    setTimeout(() => document.getElementById('newElectionName').focus(), 50);
}

function closeElectionModal() {
    document.getElementById('election-modal-overlay').classList.add('d-none');
}

async function addElection() {
    const name   = document.getElementById('newElectionName').value.trim();
    const start  = document.getElementById('newElectionStart').value;
    const end    = document.getElementById('newElectionEnd').value;
    const voters = parseInt(document.getElementById('newElectionVoters').value) || 450;
    const status = document.getElementById('newElectionStatus').value;

    if (!name)  { showToast('Please enter an election name.','error'); return; }
    if (!start) { showToast('Please enter a start date.','error'); return; }
    if (!end)   { showToast('Please enter an end date.','error'); return; }
    if (end < start) { showToast('End date cannot be before start date.','error'); return; }
    if (elections.some(e => e.name.toLowerCase() === name.toLowerCase())) {
        showToast('An election with that name already exists.','error'); return;
    }

    const btn = document.querySelector('#election-modal-overlay .btn-primary-cta');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>Creating...';
    }

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_election', name, start_date: start, status })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            closeElectionModal();
            renderElectionsList();
            showToast(`"${name}" created!`,'success');
            updateAuthBadge();
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ── Edit Election Modal ─────────────────
function showEditElectionModal(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    document.getElementById('editElectionId').value = elec.id;
    document.getElementById('editElectionName').value = elec.name;
    document.getElementById('editElectionDate').value = elec.startDate;
    document.getElementById('edit-election-modal-overlay').classList.remove('d-none');
    setTimeout(() => document.getElementById('editElectionName').focus(), 50);
}

function closeEditElectionModal() {
    document.getElementById('edit-election-modal-overlay').classList.add('d-none');
}

async function saveEditElection() {
    const id   = document.getElementById('editElectionId').value;
    const name = document.getElementById('editElectionName').value.trim();
    const date = document.getElementById('editElectionDate').value;

    if (!name) { showToast('Please enter an election name.','error'); return; }
    if (!date) { showToast('Please enter an election date.','error'); return; }

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'edit_election', election_id: id, name, date })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            closeEditElectionModal();
            renderElectionsList();
            renderOverview();
            showToast('Election updated successfully!', 'success');
            updateAuthBadge();
        } else {
            showToast('Error updating election.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ══════════════════════════════════════════════════════
//  CANDIDATES TAB
// ══════════════════════════════════════════════════════
function populateCandElectionFilter() {
    const sel = document.getElementById('cand-election-filter');
    const cur = sel.value;
    sel.innerHTML = '<option value="">Select Election</option>'
        + elections.filter(e => !e.archived).map(e => `<option value="${e.id}" ${e.id===cur?'selected':''}>${escapeHtml(e.name)}</option>`).join('');
}

function renderCandidatesTab() {
    const elecId = document.getElementById('cand-election-filter').value;
    const elec   = getElection(elecId);

    renderPositionManager(elec);
    populatePartyDropdown(elec);
    renderPositionDropdowns(elec);

    const container = document.getElementById('party-list-container');
    if (!elec) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-arrow-up-circle"></i>Select an election above to manage its candidates.</div>`;
        return;
    }
    if (elec.partyLists.length === 0) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-people"></i>No party lists yet. Click "Add Party" to create one.</div>`;
        return;
    }
    container.innerHTML = elec.partyLists.map(party => `
        <div class="admin-party-card" id="party-card-${party.id}">
            <div class="admin-party-header">
                <div class="admin-party-header-left" style="flex:1;">
                    <div class="admin-party-icon"><i class="bi bi-people-fill"></i></div>
                    <div id="party-name-display-${party.id}" style="flex:1;">
                        <div class="admin-party-name">${escapeHtml(party.name)}</div>
                        <div class="admin-party-count">${party.candidates.length} candidate${party.candidates.length!==1?'s':''}</div>
                    </div>
                </div>
                <div id="party-actions-${party.id}" style="display:flex; gap:8px;">
                    <button class="btn-icon-info" onclick="startEditParty('${elecId}','${party.id}')" title="Edit Party Name"><i class="bi bi-pencil-fill"></i></button>
                    <button class="btn-icon-danger" onclick="deleteParty('${elecId}','${party.id}')" title="Delete Party"><i class="bi bi-trash-fill"></i></button>
                </div>
            </div>
            <div class="admin-cand-list">
                ${party.candidates.length === 0
                    ? `<p style="color:var(--text-light);font-size:.8rem;padding:6px 4px;">No candidates yet.</p>`
                    : party.candidates.map(c => `
                        <div class="admin-cand-item" id="cand-item-${c.id}">
                            <div class="admin-cand-item-left">
                                <div class="admin-cand-avatar"><i class="bi bi-person-fill"></i></div>
                                <div>
                                    <div class="admin-cand-pos-label">${escapeHtml(c.position)}</div>
                                    <div class="admin-cand-item-name" id="cand-name-display-${c.id}">${escapeHtml(c.name)}</div>
                                </div>
                            </div>
                            <div class="admin-cand-item-actions">
                                <button class="admin-btn-edit-cand" onclick="startEditCandidate('${elecId}','${party.id}','${c.id}')" title="Edit"><i class="bi bi-pencil-fill"></i></button>
                                <button class="admin-btn-delete-cand" onclick="deleteCandidate('${elecId}','${party.id}','${c.id}')" title="Remove"><i class="bi bi-trash"></i></button>
                            </div>
                        </div>`).join('')
                }
            </div>
        </div>`).join('');
}

// ── Position Management ─────────────────
function renderPositionManager(elec) {
    const container = document.getElementById('position-manager-list');
    if (!container) return;
    if (!elec) { container.innerHTML = ''; return; }
    container.innerHTML = elec.customPositions.map((pos, idx) => `
            <div class="position-tag-item">
                <i class="bi bi-briefcase-fill" style="font-size:.75rem;"></i>
                ${escapeHtml(pos)}
                <button onclick="deletePosition('${elec.id}',${idx})" title="Remove"><i class="bi bi-x"></i></button>
            </div>`).join('');
}

function showAddPositionForm() {
    const form = document.getElementById('add-position-form');
    form.classList.toggle('d-none');
    if (!form.classList.contains('d-none')) document.getElementById('newPositionName').focus();
}

async function addCustomPosition() {
    const elecId = document.getElementById('cand-election-filter').value;
    const elec   = getElection(elecId);
    if (!elec)  { showToast('Please select an election first.','error'); return; }
    const input  = document.getElementById('newPositionName');
    const name   = input.value.trim();
    if (!name) { showToast('Enter a position name.','error'); return; }
    if (elec.customPositions.some(p => p.toLowerCase() === name.toLowerCase())) {
        showToast('Position already exists.','error'); return;
    }
    
    const btn = document.querySelector('#add-position-form .btn-primary-sm');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>...'; }

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_position', election_id: elecId, title: name })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            const updatedElec = getElection(elecId);
            input.value = '';
            document.getElementById('add-position-form').classList.add('d-none');
            renderPositionManager(updatedElec);
            renderPositionDropdowns(updatedElec);
            showToast(`Position "${name}" added!`,'success');
        } else {
            showToast(data.error || 'Error adding position.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Add'; }
    }
}

async function deletePosition(elecId, idx) {
    const elec = getElection(elecId);
    if (!elec) return;
    const pos = elec.customPositions[idx];
    if (!confirm(`Remove position "${pos}"?`)) return;
    
    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_position', election_id: elecId, title: pos })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            const updatedElec = getElection(elecId);
            renderPositionManager(updatedElec);
            renderPositionDropdowns(updatedElec);
            showToast(`Position "${pos}" removed.`,'info');
        } else {
            showToast(data.error || 'Error removing position.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

function renderPositionDropdowns(elec) {
    const sel = document.getElementById('candPosition');
    if (!sel) return;
    const cur = sel.value;
    sel.innerHTML = '<option value="" disabled selected>Select Position</option>'
        + (elec ? elec.customPositions.map(p => `<option value="${p}"${p===cur?' selected':''}>${escapeHtml(p)}</option>`).join('') : '');
}

// ── Party Management ─────────────────────
function showAddPartyModal() {
    const elecId = document.getElementById('cand-election-filter').value;
    if (!elecId) { showToast('Please select an election first.','error'); return; }
    document.getElementById('newPartyName').value = '';
    document.getElementById('party-modal-overlay').classList.remove('d-none');
    setTimeout(() => document.getElementById('newPartyName').focus(), 50);
}

function closePartyModal() {
    document.getElementById('party-modal-overlay').classList.add('d-none');
}

async function addParty() {
    const elecId = document.getElementById('cand-election-filter').value;
    const elec   = getElection(elecId);
    if (!elec)   { showToast('No election selected.','error'); return; }
    const name   = document.getElementById('newPartyName').value.trim();
    if (!name)   { showToast('Please enter a party name.','error'); return; }
    if (elec.partyLists.some(p => p.name.toLowerCase() === name.toLowerCase())) {
        showToast('A party with that name already exists.','error'); return;
    }

    const btn = document.querySelector('#party-modal-overlay .btn-primary-cta');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>Creating...';
    }

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_party', name })
        });
        const data = await res.json();
        if (data.success) {
            // Temporarily push to UI array so you can immediately select it to add candidates
            elec.partyLists.push({ id: data.party_id, name, candidates: [] });
            closePartyModal();
            renderCandidatesTab();
            populatePartyDropdown(elec);
            showToast(`"${name}" added!`,'success');
        } else {
            showToast(data.error || 'Error adding party.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-lg me-1"></i>Create Party';
        }
    }
}

async function deleteParty(elecId, partyId) {
    const elec  = getElection(elecId);
    if (!elec) return;
    const party = elec.partyLists.find(p => p.id === partyId);
    if (!party) return;
    if (!confirm(`Delete "${party.name}" and all its candidates?`)) return;
    
    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_party', party_id: partyId })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            renderCandidatesTab();
            showToast('Party list removed.', 'info');
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

function populatePartyDropdown(elec) {
    const sel = document.getElementById('candParty');
    if (!sel) return;
    sel.innerHTML = '<option value="" disabled selected>Select Party</option>'
        + (elec ? elec.partyLists.map(p => `<option value="${p.id}">${escapeHtml(p.name)}</option>`).join('') : '');
}

// ── Edit Party Name ──────────────────────
function startEditParty(elecId, partyId) {
    const elec  = getElection(elecId);
    const party = elec?.partyLists.find(p => p.id === partyId);
    if (!party) return;
    const displayEl = document.getElementById(`party-name-display-${partyId}`);
    displayEl.innerHTML = `
        <div style="display:flex; gap:6px; align-items:center; width:100%;">
            <input type="text" id="edit-party-name-${partyId}" class="field-input" value="${escapeHtml(party.name)}" style="height:32px;font-size:.9rem;padding:0 10px; flex:1; min-width:120px;">
            <button class="btn-success-sm" onclick="saveEditParty('${elecId}','${partyId}')" style="padding:0 10px; height:32px; border-radius:6px;" title="Save"><i class="bi bi-check-lg"></i></button>
            <button onclick="renderCandidatesTab()" style="padding:0 10px; height:32px; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); color:white; border-radius:6px; cursor:pointer;" title="Cancel"><i class="bi bi-x-lg"></i></button>
        </div>`;
        
    // Hide the normal edit/delete buttons while renaming to prevent UI clutter
    const actionsDiv = document.getElementById(`party-actions-${partyId}`);
    if (actionsDiv) actionsDiv.style.display = 'none';
}
async function saveEditParty(elecId, partyId) {
    const newName = document.getElementById(`edit-party-name-${partyId}`)?.value.trim();
    if (!newName) { showToast('Party name cannot be empty.','error'); return; }
    try {
        const res = await fetch('admin_api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'edit_party', party_id: partyId, name: newName }) });
        const data = await res.json();
        if (data.success) { await loadElectionsFromDB(); renderCandidatesTab(); showToast('Party updated successfully!', 'success'); }
        else { showToast('Error: ' + data.error, 'error'); }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ── Candidate Management ─────────────────
function toggleCandidateForm() {
    const form = document.getElementById('candidate-form');
    form.classList.toggle('d-none');
    if (!form.classList.contains('d-none')) {
        const elecId = document.getElementById('cand-election-filter').value;
        const elec   = getElection(elecId);
        populatePartyDropdown(elec);
        renderPositionDropdowns(elec);
        document.getElementById('candName').focus();
    }
}

async function addCandidate() {
    const elecId   = document.getElementById('cand-election-filter').value;
    const elec     = getElection(elecId);
    if (!elec)     { showToast('Please select an election first.','error'); return; }
    const name     = document.getElementById('candName').value.trim();
    const position = document.getElementById('candPosition').value;
    const partyId  = document.getElementById('candParty').value;

    if (!name)     { showToast('Please enter the candidate name.','error'); return; }
    if (!position) { showToast('Please select a position.','error'); return; }
    if (!partyId)  { showToast('Please select a party list.','error'); return; }

    const party = elec.partyLists.find(p => p.id === partyId);
    if (!party) { showToast('Party not found.','error'); return; }

    // The client-side check preventing multiple candidates for the same position in a party has been removed.
    // This allows adding multiple candidates (e.g., Senators) to a single partylist.
    const btn = document.querySelector('#candidate-form .btn-success-sm');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-arrow-repeat spin me-1"></i>Adding...';
    }

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_candidate', election_id: elecId, party_id: partyId, position, name })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            document.getElementById('candName').value = '';
            document.getElementById('candPosition').selectedIndex = 0;
            document.getElementById('candParty').selectedIndex = 0;
            renderCandidatesTab();
            showToast(`${name} added as ${position}!`, 'success');
        } else {
            showToast(data.error || 'Error adding candidate.', 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-person-check-fill me-1"></i>Add';
        }
    }
}

async function deleteCandidate(elecId, partyId, candId) {
    const elec  = getElection(elecId);
    if (!elec)  return;
    const party = elec.partyLists.find(p => p.id === partyId);
    if (!party) return;
    const cand  = party.candidates.find(c => c.id === candId);
    if (!cand)  return;

    if (!confirm(`Remove candidate "${cand.name}"?`)) return;

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_candidate', candidate_id: candId })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            renderCandidatesTab();
            showToast('Candidate removed.', 'info');
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ── Edit candidate name & position ───────
function startEditCandidate(elecId, partyId, candId) {
    const elec  = getElection(elecId);
    const party = elec?.partyLists.find(p => p.id === partyId);
    const cand  = party?.candidates.find(c => c.id === candId);
    if (!cand) return;

    const itemEl = document.getElementById(`cand-item-${candId}`);
    const posOptions = elec.customPositions.map(p => 
        `<option value="${escapeHtml(p)}" ${p === cand.position ? 'selected' : ''}>${escapeHtml(p)}</option>`
    ).join('');

    itemEl.innerHTML = `
        <div class="admin-cand-item-left" style="width: 100%; align-items:center;">
            <div class="admin-cand-avatar"><i class="bi bi-pencil"></i></div>
            <div style="display:flex; gap:10px; width:100%; align-items:center;">
                <input type="text" id="edit-name-${candId}" class="field-input" value="${escapeHtml(cand.name)}" style="height:32px;font-size:.85rem;padding:0 10px; flex:1;" placeholder="Candidate Name">
                <select id="edit-pos-${candId}" class="field-input field-select" style="height:32px;font-size:.85rem;padding:0 10px; width:150px;">
                    ${posOptions}
                </select>
                <div style="display:flex; gap:5px;">
                    <button class="btn-success-sm" onclick="saveEditCandidate('${elecId}','${partyId}','${candId}')" style="padding:0 8px; height:32px;" title="Save"><i class="bi bi-check-lg"></i></button>
                    <button class="btn-ghost-sm" onclick="renderCandidatesTab()" style="padding:0 8px; height:32px;" title="Cancel"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>
        </div>`;
}

async function saveEditCandidate(elecId, partyId, candId) {
    const newName = document.getElementById(`edit-name-${candId}`)?.value.trim();
    const newPos  = document.getElementById(`edit-pos-${candId}`)?.value;
    if (!newName) { showToast('Name cannot be empty.','error'); return; }
    if (!newPos)  { showToast('Position cannot be empty.','error'); return; }
    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'edit_candidate', candidate_id: candId, name: newName, position: newPos })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            renderCandidatesTab();
            showToast('Candidate updated successfully!', 'success');
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ══════════════════════════════════════════════════════
//  RESULTS TAB — live tally from get_stats.php
// ══════════════════════════════════════════════════════
function populateResultsFilter() {
    const sel = document.getElementById('results-election-filter');
    const cur = sel.value;
    sel.innerHTML = '<option value="">Select Election</option>'
        + elections.map(e => `<option value="${e.id}" ${e.id===cur?'selected':''}>${escapeHtml(e.name)}</option>`).join('');
    if (!cur && elections.length > 0) sel.value = getActiveElections()[0]?.id || elections[0].id;
}

async function renderResults() {
    Object.values(chartInstances).forEach(ch => ch.destroy());
    chartInstances = {};

    const elecId       = document.getElementById('results-election-filter').value;
    const statsGrid    = document.getElementById('results-stats-grid');
    const chartsContainer = document.getElementById('results-charts-container');
    if (!elecId) {
        statsGrid.innerHTML = '';
        chartsContainer.innerHTML = `<div class="admin-empty-state"><i class="bi bi-arrow-up-circle"></i>Select an election above to view results.</div>`;
        return;
    }

    statsGrid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:1rem;color:var(--text-mid);">Loading tally…</div>';
    chartsContainer.innerHTML = '';

    try {
        const response = await fetch(`get_stats.php?section=tally&election_id=${encodeURIComponent(elecId)}`);
        const data     = await response.json();

        if (!data.success || !data.elections?.length) throw new Error('No data');

        const elec   = data.elections[0];
        const localE = getElection(elecId);

        statsGrid.innerHTML = `
            <div class="stat-card stat-blue">
                <div class="stat-icon-wrap stat-icon-blue"><i class="bi bi-people-fill"></i></div>
                <div class="stat-label">Votes Cast</div>
                <div class="stat-value">${elec.votes_cast}</div>
                <div class="stat-sub">Out of ${elec.eligible_voters} eligible voters</div>
            </div>
            <div class="stat-card stat-green">
                <div class="stat-icon-wrap stat-icon-green"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="stat-label">Voter Turnout</div>
                <div class="stat-value">${elec.turnout_pct}%</div>
                <div class="stat-sub">Participation rate</div>
            </div>
            <div class="stat-card stat-gold">
                <div class="stat-icon-wrap stat-icon-gold"><i class="bi bi-activity"></i></div>
                <div class="stat-label">Session</div>
                <div class="stat-value" id="stat-session">0h 0m</div>
                <div class="stat-sub">Election ${elec.status}</div>
            </div>`;

        const positions = Object.entries(elec.positions || {});
        const colors    = ['#3b82f6','#7c3aed','#ec4899','#10b981','#f59e0b','#ef4444'];

        if (positions.length === 0) {
            chartsContainer.innerHTML = `<div class="admin-empty-state"><i class="bi bi-bar-chart"></i>No candidate positions set up for this election.</div>`;
            return;
        }

        positions.forEach(([pos, candidates]) => {
            if (!candidates.length) return;
            const labels   = candidates.map(c => c.name);
            const dataVals = candidates.map(c => c.votes);
            const bgColors = candidates.map((_,i) => colors[i % colors.length] + 'cc');
            const bdColors = candidates.map((_,i) => colors[i % colors.length]);
            const total    = dataVals.reduce((a,b)=>a+b,0);
            const chartId  = `chart-${pos.replace(/\s+/g,'-').replace(/[^a-zA-Z0-9-]/g,'')}`;

            const card = document.createElement('div');
            card.className = 'results-chart-card';
            card.innerHTML = `
                <div class="results-chart-title">
                    <i class="bi bi-bar-chart-fill" style="color:var(--nu-blue);"></i>
                    ${escapeHtml(pos)} Results
                    <span class="live-badge ms-auto"><i class="bi bi-activity me-1"></i>Live</span>
                </div>
                <div class="results-chart-wrap"><canvas id="${chartId}"></canvas></div>
                <div class="results-legend">
                    ${candidates.map((c, i) => {
                        const pct = total > 0 ? Math.round((c.votes/total)*100) : 0;
                        return `<div class="results-legend-item">
                            <span class="legend-dot" style="background:${colors[i%colors.length]};"></span>
                            <span class="legend-name">${escapeHtml(c.name)}</span>
                            <span class="legend-votes">${c.votes} votes (${pct}%)</span>
                        </div>`;
                    }).join('')}
                </div>`;
            chartsContainer.appendChild(card);

            setTimeout(() => {
                const ctx = document.getElementById(chartId);
                if (!ctx) return;
                chartInstances[chartId] = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{ data: dataVals, backgroundColor: bgColors, borderColor: bdColors, borderWidth: 2, borderRadius: 8, borderSkipped: false }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: true,
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: ctx => { const pct = total>0?Math.round((ctx.parsed.y/total)*100):0; return ` ${ctx.parsed.y} votes (${pct}%)`; } } }
                        },
                        scales: {
                            y: { beginAtZero: true, grid: { color:'rgba(0,0,0,0.04)' }, ticks: { font: { family:'Sora', size:11 } } },
                            x: { grid: { display:false }, ticks: { font: { family:'Sora', size:11 }, color:'#4a6080' } }
                        }
                    }
                });
            }, 50);
        });

    } catch (err) {
        console.error('Results error:', err);
        statsGrid.innerHTML = '';
        chartsContainer.innerHTML = `<div class="admin-empty-state"><i class="bi bi-exclamation-triangle"></i>Error loading results. Check server connection.</div>`;
    }
}

// ══════════════════════════════════════════════════════
//  ARCHIVE TAB
// ══════════════════════════════════════════════════════
function renderArchive() {
    const archived = getClosedElections();
    const container = document.getElementById('archive-list');
    if (archived.length === 0) {
        container.innerHTML = `<div class="admin-empty-state"><i class="bi bi-archive"></i>No archived elections yet.</div>`;
        return;
    }
    container.innerHTML = archived.map(e => {
        const total   = Object.values(e.voteTallies).reduce((a,b)=>a+b,0);
        const pos     = Math.max(getAllPositions(e).length, 1);
        const vCast   = Math.round(total / pos);
        const turnout = Math.min(100, Math.round((vCast / (e.eligibleVoters||1)) * 100));
        return `
        <div class="archive-card">
            <div class="archive-icon"><i class="bi bi-archive-fill"></i></div>
            <div>
                <div class="archive-name">${escapeHtml(e.name)}</div>
                <div class="archive-dates">${formatDateRange(e.startDate, e.endDate)} · ${vCast} votes · ${turnout}% turnout</div>
            </div>
            <div class="archive-actions">
                <button class="btn-outline-sm" onclick="viewArchivedResults('${e.id}')"><i class="bi bi-bar-chart me-1"></i>View Results</button>
                <button class="btn-icon-danger" onclick="permanentlyDeleteElection('${e.id}')" title="Delete permanently"><i class="bi bi-trash-fill"></i></button>
            </div>
        </div>`;
    }).join('');
}

function viewArchivedResults(elecId) {
    switchAdminTab('results');
    setTimeout(() => {
        populateResultsFilter();
        document.getElementById('results-election-filter').value = elecId;
        renderResults();
    }, 50);
}

async function permanentlyDeleteElection(elecId) {
    const elec = getElection(elecId);
    if (!elec) return;
    if (!confirm(`Permanently delete "${elec.name}"? All data will be lost.`)) return;
    
    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete_election', election_id: elecId })
        });
        const data = await res.json();
        if (data.success) {
            await loadElectionsFromDB();
            renderArchive();
            renderOverview();
            showToast('Election permanently deleted.', 'info');
            updateAuthBadge();
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ══════════════════════════════════════════════════════
//  CLOCK
// ══════════════════════════════════════════════════════
function startClock() {
    stopClock();
    updateClock(); updateSessionTime();
    clockInterval = setInterval(() => { updateClock(); updateSessionTime(); }, 1000);
}
function stopClock() {
    if (clockInterval) { clearInterval(clockInterval); clockInterval = null; }
}
function updateClock() {
    const el = document.getElementById('live-clock');
    if (el) el.textContent = new Date().toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
}
function updateSessionTime() {
    const el = document.getElementById('stat-session');
    if (!el) return;
    const elapsed = Math.floor((Date.now() - sessionStartTime) / 1000);
    const h = Math.floor(elapsed / 3600);
    const m = Math.floor((elapsed % 3600) / 60);
    el.textContent = `${h}h ${m}m`;
}

// ══════════════════════════════════════════════════════
//  TOAST
// ══════════════════════════════════════════════════════
function showToast(message, type = 'success') {
    const box  = document.getElementById('toast-box');
    const msg  = document.getElementById('toast-msg');
    const icon = document.getElementById('toast-icon');
    const icons = { success: '<i class="bi bi-check-circle-fill"></i>', error: '<i class="bi bi-x-circle-fill"></i>', info: '<i class="bi bi-info-circle-fill"></i>' };
    box.className = `toast-box ${type}`;
    icon.innerHTML = icons[type] || icons.success;
    msg.textContent = message;
    box.classList.add('show');
    clearTimeout(box._timer);
    box._timer = setTimeout(() => box.classList.remove('show'), 3600);
}

// ══════════════════════════════════════════════════════
//  DATE HELPERS
// ══════════════════════════════════════════════════════
function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
}

function formatDateRange(start, end) {
    if (!start) return '—';
    const s = new Date(start + 'T00:00:00');
    const e = new Date(end   + 'T00:00:00');
    const opts = { month: 'short', day: 'numeric' };
    if (!end || start === end) return s.toLocaleDateString('en-PH', opts) + ', ' + s.getFullYear();
    if (s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear()) {
        return s.toLocaleDateString('en-PH', { month:'long' }) + ' ' + s.getDate() + '–' + e.getDate() + ', ' + e.getFullYear();
    }
    return s.toLocaleDateString('en-PH', opts) + ' – ' + e.toLocaleDateString('en-PH', opts) + ', ' + e.getFullYear();
}

// ══════════════════════════════════════════════════════
//  DATABASE SYNC — load elections from get_stats.php
// ══════════════════════════════════════════════════════
async function loadElectionsFromDB() {
    try {
        const response = await fetch('get_stats.php?section=tally');
        const data     = await response.json();

        if (data.success && Array.isArray(data.elections)) {

            elections = data.elections.map(e => {
                let partyMap    = {};
                let voteTallies = {};
                const customPositions = Object.keys(e.positions || {});

                // Pre-fill parties directly from the database to retain true IDs
                if (data.parties) {
                    data.parties.forEach(p => {
                        partyMap[p.name] = { id: p.id, name: p.name, candidates: [] };
                    });
                }

                for (const [posName, candidates] of Object.entries(e.positions || {})) {
                    candidates.forEach(c => {
                        voteTallies[c.candidate_id] = c.votes;
                        if (c.candidate_id.startsWith('ABSTAIN__')) return;

                        const partyName = c.party || 'Independent';
                        if (!partyMap[partyName]) {
                            partyMap[partyName] = {
                                id: c.party_id || ('party-' + partyName.replace(/\s+/g,'-').toLowerCase()),
                                name: partyName,
                                candidates: []
                            };
                        }
                        partyMap[partyName].candidates.push({
                            id: c.candidate_id,
                            name: c.name,
                            position: posName
                        });
                    });
                }

                return {
                    id:             e.id,
                    name:           e.name,
                    startDate:      e.date ? e.date.split(' ')[0] : '',
                    endDate:        e.date ? e.date.split(' ')[0] : '',
                    eligibleVoters: e.eligible_voters || 0,
                    status:         e.status || 'active',
                    archived:       e.status === 'archived',
                    voted:          e.user_voted || false,
                    partyLists:     Object.values(partyMap),
                    customPositions,
                    positionInfo:   e.position_info || {},
                    voteTallies,
                    archivedTallies: []
                };
            });
        }
    } catch (err) {
        console.error('Error loading elections from DB:', err);
    }
}

// ══════════════════════════════════════════════════════
//  ADMIN PASSWORD CHANGE
// ══════════════════════════════════════════════════════
function showAdminPasswordModal() {
    document.getElementById('adminOldPass').value = '';
    document.getElementById('adminNewPass').value = '';
    document.getElementById('adminConfirmPass').value = '';
    document.getElementById('admin-password-modal-overlay').classList.remove('d-none');
}

function closeAdminPasswordModal() {
    document.getElementById('admin-password-modal-overlay').classList.add('d-none');
}

async function saveAdminPassword() {
    const oldPass = document.getElementById('adminOldPass').value;
    const newPass = document.getElementById('adminNewPass').value;
    const confirmPass = document.getElementById('adminConfirmPass').value;

    if (!oldPass) { showToast('Enter your current password.', 'error'); return; }
    if (newPass.length < 6) { showToast('New password must be at least 6 characters.', 'error'); return; }
    if (newPass !== confirmPass) { showToast('New passwords do not match.', 'error'); return; }

    try {
        const res = await fetch('admin_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'change_password', old_password: oldPass, new_password: newPass })
        });
        const data = await res.json();
        if (data.success) {
            closeAdminPasswordModal();
            showToast('Password updated successfully! Please log in again.', 'success');
            setTimeout(() => {
                showAuthPage(); // Logs the user out on the client side
            }, 1500);
        } else {
            showToast('Error: ' + data.error, 'error');
        }
    } catch (err) {
        showToast('Network error.', 'error');
    }
}

// ══════════════════════════════════════════════════════
//  INIT
// ══════════════════════════════════════════════════════
window.addEventListener('DOMContentLoaded', async () => {
    await loadElectionsFromDB();

    // Populate the tracker election filter if it exists
    const trackerFilter = document.getElementById('tracker-election-filter');
    if (trackerFilter) {
        trackerFilter.innerHTML = '<option value="">All Elections</option>'
            + elections.map(e => `<option value="${e.id}">${escapeHtml(e.name)}</option>`).join('');
    }

    // Handle PHP redirect params first; if not a redirect, show auth page
    const handled = handleUrlParams();
    if (!handled) showAuthPage();
});