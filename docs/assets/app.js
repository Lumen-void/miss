const integrations = [
  ["Marketplace API", "Linked"],
  ["Commerce Admin", "Linked"],
  ["Settlement Provider", "Demo"],
  ["Warehouse Upload", "Manual"]
];

const renderEmptyRow = (target, colspan, label) => {
  document.querySelector(target).innerHTML = `
  <tr>
    <td colspan="${colspan}" class="empty-cell">
      <strong>No database table rows are published.</strong>
      <span>${label}</span>
    </td>
  </tr>
  `;
};

renderEmptyRow("#sourceRows", 5, "The local source, record count, match rate, exception and status data has been cleared from the public deployment.");
renderEmptyRow("#orderRows", 5, "Order, channel, gross, deduction and net values are intentionally blank in the public deployment.");

document.querySelector("#integrations").innerHTML = integrations.map(item => `
  <div class="integration">
    <strong>${item[0]}</strong>
    <span class="badge ${item[1] === "Linked" ? "ok" : "warn"}">${item[1]}</span>
  </div>
`).join("");

const toast = document.querySelector("#toast");
const showToast = (message) => {
  toast.textContent = message;
  toast.classList.add("show");
  window.clearTimeout(window.toastTimer);
  window.toastTimer = window.setTimeout(() => toast.classList.remove("show"), 2600);
};

document.querySelectorAll("[data-action]").forEach(button => {
  button.addEventListener("click", () => {
    const action = button.dataset.action;
    showToast(action === "sync"
      ? "Demo sync completed with fake marketplace records."
      : "Sample MIS export prepared. No private files were used.");
  });
});

document.querySelectorAll("nav a").forEach(link => {
  const route = link.dataset.view;
  const page = window.location.pathname.split("/").pop().replace(".html", "") || "dashboard";
  link.classList.toggle("active", route === page || (page === "index" && route === "dashboard"));
});

const currentPage = window.location.pathname.split("/").pop().replace(".html", "") || "dashboard";
const target = document.querySelector(`[data-panel="${currentPage}"], #${currentPage}`);
if (target && currentPage !== "dashboard" && currentPage !== "index") {
  requestAnimationFrame(() => target.scrollIntoView({ block: "start" }));
}

document.querySelectorAll(".step").forEach(step => {
  step.addEventListener("click", () => {
    document.querySelectorAll(".step").forEach(item => item.classList.remove("active"));
    step.classList.add("active");
    showToast(`${step.textContent} selected for the public demo flow.`);
  });
});
