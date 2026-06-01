const sourceRows = [
  ["Amazon Seller", "18,420", "98.9%", "6", "Ready"],
  ["Shopify D2C", "4,870", "99.4%", "2", "Ready"],
  ["Retail POS", "9,214", "97.8%", "5", "Review"],
  ["Warehouse WMS", "11,902", "96.2%", "7", "Review"]
];

const integrations = [
  ["Amazon SP-API", "Connected"],
  ["Shopify Admin", "Connected"],
  ["Razorpay Settlements", "Demo"],
  ["Warehouse CSV", "Manual"]
];

const orderRows = [
  ["ORD-10492", "Amazon", "₹12,480", "₹1,240", "₹11,240"],
  ["ORD-10508", "Shopify", "₹8,990", "₹410", "₹8,580"],
  ["ORD-10544", "POS", "₹15,400", "₹0", "₹15,400"],
  ["ORD-10577", "Amazon", "₹6,720", "₹680", "₹6,040"],
  ["ORD-10611", "Shopify", "₹11,260", "₹520", "₹10,740"]
];

document.querySelector("#sourceRows").innerHTML = sourceRows.map(row => `
  <tr>
    <td><strong>${row[0]}</strong><span class="muted">Sample connector</span></td>
    <td>${row[1]}</td>
    <td>${row[2]}</td>
    <td>${row[3]}</td>
    <td><span class="badge ${row[4] === "Ready" ? "ok" : "warn"}">${row[4]}</span></td>
  </tr>
`).join("");

document.querySelector("#integrations").innerHTML = integrations.map(item => `
  <div class="integration">
    <strong>${item[0]}</strong>
    <span class="badge ${item[1] === "Connected" ? "ok" : "warn"}">${item[1]}</span>
  </div>
`).join("");

document.querySelector("#orderRows").innerHTML = orderRows.map(row => `
  <tr>${row.map(cell => `<td>${cell}</td>`).join("")}</tr>
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
  link.addEventListener("click", () => {
    document.querySelectorAll("nav a").forEach(item => item.classList.remove("active"));
    link.classList.add("active");
  });
});

document.querySelectorAll(".step").forEach(step => {
  step.addEventListener("click", () => {
    document.querySelectorAll(".step").forEach(item => item.classList.remove("active"));
    step.classList.add("active");
    showToast(`${step.textContent} selected for the public demo flow.`);
  });
});
