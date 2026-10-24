/**
 * CivicShield - Main JavaScript Application Module
 * Integrates jQuery, Bootstrap 5, Leaflet, and Accessible Interaction Helpers
 */

(() => {
  "use strict";

  // Escape untrusted text before inserting it into HTML.
  const escapeHTML = (str) => {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>'"]/g, (c) => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      "'": "&#39;",
      '"': "&quot;",
    }[c]));
  };

  // Retrieve CSRF token and Base URL from document meta tags
  const csrfToken =
    document
      .querySelector('meta[name="csrf-token"]')
      ?.getAttribute("content") || "";
  const baseUrl =
    document.querySelector('meta[name="base-url"]')?.getAttribute("content") ||
    "";

  // Automatically bind CSRF token to all jQuery AJAX calls
  if (window.jQuery) {
    $.ajaxSetup({
      headers: {
        "X-CSRF-Token": csrfToken,
      },
    });
  }

  // Expose CivicShield global utility
  window.CivicShield = {
    csrfToken: csrfToken,
    baseUrl: baseUrl,

    /**
     * Standardized Fetch API wrapper with JSON and CSRF support
     */
    async request(endpoint, options = {}) {
      const url = endpoint.startsWith("http")
        ? endpoint
        : `${baseUrl}${endpoint}`;
      const headers = {
        Accept: "application/json",
        "X-CSRF-Token": csrfToken,
        ...(options.headers || {}),
      };

      if (
        options.body &&
        !(options.body instanceof FormData) &&
        typeof options.body === "object"
      ) {
        headers["Content-Type"] = "application/json";
        options.body = JSON.stringify(options.body);
      }

      try {
        const response = await fetch(url, { ...options, headers });
        const json = await response.json();
        return { ok: response.ok, status: response.status, ...json };
      } catch (err) {
        console.error("[CivicShield Request Error]", err);
        return {
          success: false,
          message: "Network or parsing error occurred.",
          errors: [err.message],
        };
      }
    },

    /**
     * Render dynamic Bootstrap alert
     */
    showAlert(containerSelector, type, message) {
      const container = document.querySelector(containerSelector);
      if (!container) return;

      const iconClass =
        type === "success"
          ? "bi-check-circle-fill"
          : type === "danger"
            ? "bi-exclamation-triangle-fill"
            : "bi-info-circle-fill";


      const alertDiv = document.createElement("div");
      alertDiv.className = `alert alert-${type} alert-dismissible fade show d-flex align-items-center gap-2`;
      alertDiv.setAttribute("role", "alert");
      const icon = document.createElement("i");
      icon.className = `bi ${iconClass} flex-shrink-0`;
      const msgDiv = document.createElement("div");
      msgDiv.className = "flex-grow-1";
      msgDiv.innerHTML = escapeHTML(message);
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "btn-close";
      btn.setAttribute("data-bs-dismiss", "alert");
      btn.setAttribute("aria-label", "Close");
      alertDiv.appendChild(icon);
      alertDiv.appendChild(msgDiv);
      alertDiv.appendChild(btn);
      container.innerHTML = "";
      container.appendChild(alertDiv);
    },
  };

  // ==========================================================================
  // DOM Ready Interactive Handlers
  // ==========================================================================
  document.addEventListener("DOMContentLoaded", () => {
    // 1. Accessible Password Visibility Toggler
    document.querySelectorAll(".toggle-password").forEach((button) => {
      button.addEventListener("click", function () {
        const targetId = this.getAttribute("data-target");
        const input = document.getElementById(targetId);
        if (!input) return;

        const isPassword = input.getAttribute("type") === "password";
        input.setAttribute("type", isPassword ? "text" : "password");
        this.setAttribute("aria-pressed", isPassword ? "true" : "false");

        const icon = this.querySelector("i");
        if (icon) {
          icon.className = isPassword ? "bi bi-eye-slash" : "bi bi-eye";
        }

        this.setAttribute(
          "aria-label",
          isPassword ? "Hide password" : "Show password",
        );
      });
    });

    // 2. Demonstration Credentials Quick-Fill Buttons
    document.querySelectorAll(".btn-demo-fill").forEach((btn) => {
      btn.addEventListener("click", function () {
        const u = this.getAttribute("data-username") || "";
        const p = this.getAttribute("data-password") || "";
        const uInput = document.getElementById("username");
        const pInput = document.getElementById("password");

        if (uInput) uInput.value = u;
        if (pInput) pInput.value = p;

        CivicShield.showAlert(
          "#authFeedback",
          "info",
          `Populated credentials for ${u}. Click "Sign In" to proceed.`,
        );
      });
    });

    // 3. Database-Backed Incident Report Token Tracker
    const trackerForm = document.getElementById("reportTrackerForm");
    if (trackerForm) {
      trackerForm.addEventListener("submit", async (e) => {
        e.preventDefault();
        const tokenInput = document.getElementById("trackingTokenInput");
        const resultBox = document.getElementById("trackerResultBox");
        if (!tokenInput || !resultBox) return;

        const token = tokenInput.value.trim();
        if (!token) {
          resultBox.innerHTML =
            '<div class="alert alert-warning py-2 mb-0">Please enter a valid tracking token.</div>';
          resultBox.classList.remove("d-none");
          return;
        }

        resultBox.innerHTML =
          '<div class="text-center py-3 text-muted"><span class="spinner-border spinner-border-sm me-2"></span> Searching database records...</div>';
        resultBox.classList.remove("d-none");

        const res = await CivicShield.request("/api/v1/track.php", {
          method: "POST",
          body: { token },
        });

        if (res.success && res.data) {
          const data = res.data;
          let badgeClass = "cs-badge-pending";
          if (data.status === "resolved") badgeClass = "cs-badge-resolved";
          else if (
            data.status === "in_progress" ||
            data.status === "consolidated" ||
            data.status === "assigned"
          )
            badgeClass = "cs-badge-in_progress";
          else if (data.status === "rejected") badgeClass = "cs-badge-critical";

          resultBox.innerHTML = `
                        <div class="card border-info bg-light p-3 shadow-sm text-start">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <div>
                                    <span class="cs-badge ${badgeClass} mb-1">${escapeHTML(data.status_label)}</span>
                                    <h6 class="fw-bold text-dark mb-0">${escapeHTML(data.title)}</h6>
                                </div>
                                <div>
                                    <span class="badge bg-secondary font-monospace">${escapeHTML(data.report_reference)}</span>
                                    ${data.incident_reference ? `<span class="badge bg-primary font-monospace ms-1">${escapeHTML(data.incident_reference)}</span>` : ""}
                                </div>
                            </div>
                            <div class="small text-muted mb-2">
                                <span><i class="bi bi-tag me-1"></i>${escapeHTML(data.category)} (${escapeHTML(data.department)})</span> &bull;
                                <span><i class="bi bi-geo-alt me-1"></i>${escapeHTML(data.neighborhood)}</span> &bull;
                                <span><i class="bi bi-calendar3 me-1"></i>Reported: ${escapeHTML(data.submission_date)}</span>
                            </div>
                            <div class="small text-secondary bg-white p-2 rounded border border-light-subtle">
                                <strong>Status:</strong> ${escapeHTML(data.status_label)}. Target SLA: ${escapeHTML(String(data.target_sla_hours))} hours.
                                ${data.resolved_date ? `<div class="text-success fw-bold mt-1"><i class="bi bi-check-circle-fill me-1"></i>Resolved on ${escapeHTML(data.resolved_date)}</div>` : ""}
                            </div>
                            <div class="mt-2 text-muted small" style="font-size:0.75rem;">
                                <i class="bi bi-shield-check text-success me-1"></i>${escapeHTML(data.privacy_guarantee)}
                            </div>
                        </div>
                    `;
        } else {
          resultBox.innerHTML = `
                        <div class="alert alert-warning py-2 mb-0 text-start">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            ${res.message || "No incident report found matching this tracking token. Please verify your token."}
                        </div>
                    `;
        }
      });
    }
  });
})();
