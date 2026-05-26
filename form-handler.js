// Generate Order ID (0716-XXXX format)
function generateOrderId() {
  const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  let result = '0716-';
  for (let i = 0; i < 4; i++) {
    result += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return result;
}

// Handle Full Service form submission
async function submitFullServiceForm(event) {
  event.preventDefault();
  
  const form = event.target;
  const submitBtn = form.querySelector('button[type="submit"]');
  const statusDiv = form.querySelector('.form-status');
  
  // Show processing state
  if (statusDiv) {
    statusDiv.classList.add('active');
    statusDiv.classList.remove('success', 'error');
    statusDiv.innerHTML = `⏳ Processing...`;
  }
  
  // Validate required fields
  const requiredFields = ['name', 'email', 'phone', 'streetAddress', 'city', 'state', 'zip', 'serviceType', 'quantity'];
  for (const field of requiredFields) {
    const input = form.querySelector(`[name="${field}"]`);
    if (!input || !input.value.trim()) {
      if (statusDiv) {
        statusDiv.classList.add('error');
        statusDiv.innerHTML = `❌ Please fill in all required fields.`;
      }
      return;
    }
  }

  // Validate email format
  const email = form.querySelector('[name="email"]').value;
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    if (statusDiv) {
      statusDiv.classList.add('error');
      statusDiv.innerHTML = `❌ Please enter a valid email address.`;
    }
    return;
  }

  submitBtn.disabled = true;

  try {
    // Collect file uploads as base64
    const files = [];
    const fileInput = form.querySelector('input[type="file"]');
    if (fileInput && fileInput.files.length > 0) {
      let totalSize = 0;
      for (const file of fileInput.files) {
        totalSize += file.size;
        if (totalSize > 75 * 1024 * 1024) {
          if (statusDiv) {
            statusDiv.classList.add('error');
            statusDiv.innerHTML = `❌ Total file size exceeds 75MB limit.`;
          }
          submitBtn.disabled = false;
          return;
        }
        
        const base64 = await fileToBase64(file);
        files.push({
          name: file.name,
          type: file.type,
          data: base64
        });
      }
    }

    const formData = {
      orderId: generateOrderId(),
      name: form.querySelector('[name="name"]').value,
      email: form.querySelector('[name="email"]').value,
      phone: form.querySelector('[name="phone"]').value,
      streetAddress: form.querySelector('[name="streetAddress"]').value,
      city: form.querySelector('[name="city"]').value,
      state: form.querySelector('[name="state"]').value,
      zip: form.querySelector('[name="zip"]').value,
      company: form.querySelector('[name="company"]')?.value || '',
      serviceType: form.querySelector('[name="serviceType"]').value,
      quantity: form.querySelector('[name="quantity"]').value,
      instructions: form.querySelector('[name="instructions"]')?.value || '',
      files: files
    };

    const response = await fetch('/.netlify/functions/submit-full-service', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(formData)
    });

    const result = await response.json();

    if (response.ok && result.success) {
      if (statusDiv) {
        statusDiv.classList.remove('error');
        statusDiv.classList.add('success');
        statusDiv.innerHTML = `✅ Your order received! Check your email for confirmation.`;
      }
      form.reset();
      setTimeout(() => {
        submitBtn.disabled = false;
      }, 2000);
    } else {
      if (statusDiv) {
        statusDiv.classList.add('error');
        statusDiv.innerHTML = `❌ Submission failed. Please try again.`;
      }
      submitBtn.disabled = false;
    }
  } catch (error) {
    console.error('Form error:', error);
    if (statusDiv) {
      statusDiv.classList.add('error');
      statusDiv.innerHTML = `❌ An error occurred. Please try again.`;
    }
    submitBtn.disabled = false;
  }
}

// Handle DIY form submission
async function submitDiyForm(event) {
  event.preventDefault();
  
  const form = event.target;
  const submitBtn = form.querySelector('button[type="submit"]');
  const statusDiv = form.querySelector('.form-status');
  
  // Show processing state
  if (statusDiv) {
    statusDiv.classList.add('active');
    statusDiv.classList.remove('success', 'error');
    statusDiv.innerHTML = `⏳ Processing...`;
  }
  
  // Validate required fields
  const requiredFields = ['name', 'email', 'phone', 'quantity'];
  for (const field of requiredFields) {
    const input = form.querySelector(`[name="${field}"]`);
    if (!input || !input.value.trim()) {
      if (statusDiv) {
        statusDiv.classList.add('error');
        statusDiv.innerHTML = `❌ Please fill in all required fields.`;
      }
      return;
    }
  }

  // Validate email format
  const email = form.querySelector('[name="email"]').value;
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    if (statusDiv) {
      statusDiv.classList.add('error');
      statusDiv.innerHTML = `❌ Please enter a valid email address.`;
    }
    return;
  }

  // Preview PNG is required
  const previewInput = form.querySelector('[name="previewPng"]');
  if (!previewInput || !previewInput.files.length) {
    if (statusDiv) {
      statusDiv.classList.add('error');
      statusDiv.innerHTML = `❌ Please upload your design preview (PNG).`;
    }
    return;
  }

  submitBtn.disabled = true;

  try {
    const previewFile = previewInput.files[0];
    const previewBase64 = await fileToBase64(previewFile);

    // Collect logo files
    const logos = [];
    const logosInput = form.querySelector('[name="logos"]');
    if (logosInput && logosInput.files.length > 0) {
      for (const file of logosInput.files) {
        const base64 = await fileToBase64(file);
        logos.push({
          name: file.name,
          type: file.type,
          data: base64
        });
      }
    }

    // Parse design JSON if provided
    let designJson = null;
    const designInput = form.querySelector('[name="designJson"]');
    if (designInput && designInput.value.trim()) {
      try {
        designJson = JSON.parse(designInput.value);
      } catch (e) {
        if (statusDiv) {
          statusDiv.classList.add('error');
          statusDiv.innerHTML = `❌ Invalid design JSON format.`;
        }
        submitBtn.disabled = false;
        return;
      }
    }

    const formData = {
      orderId: generateOrderId(),
      name: form.querySelector('[name="name"]').value,
      email: form.querySelector('[name="email"]').value,
      phone: form.querySelector('[name="phone"]').value,
      quantity: form.querySelector('[name="quantity"]').value,
      previewPng: previewBase64,
      logos: logos,
      designJson: designJson
    };

    const response = await fetch('/.netlify/functions/submit-diy', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(formData)
    });

    const result = await response.json();

    if (response.ok && result.success) {
      if (statusDiv) {
        statusDiv.classList.remove('error');
        statusDiv.classList.add('success');
        statusDiv.innerHTML = `✅ Your order received! Check your email for confirmation.`;
      }
      form.reset();
      setTimeout(() => {
        submitBtn.disabled = false;
      }, 2000);
    } else {
      if (statusDiv) {
        statusDiv.classList.add('error');
        statusDiv.innerHTML = `❌ Submission failed. Please try again.`;
      }
      submitBtn.disabled = false;
    }
  } catch (error) {
    console.error('Form error:', error);
    if (statusDiv) {
      statusDiv.classList.add('error');
      statusDiv.innerHTML = `❌ An error occurred. Please try again.`;
    }
    submitBtn.disabled = false;
  }
}

// Convert file to base64
function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}

// Wire up form button clicks
document.addEventListener('DOMContentLoaded', () => {
  // Register Full Service buttons
  function registerFullServiceButtons() {
    document.querySelectorAll('button').forEach(btn => {
      const text = btn.textContent?.trim() || '';
      const hasDataAttr = btn.getAttribute('data-form');
      
      // Register buttons with explicit data-form attribute
      if (hasDataAttr === 'full-service') {
        btn.addEventListener('click', openFullServiceModal);
        return;
      }
      
      // Register buttons by text content (hero, sections, pricing)
      if (text.includes('GET YOUR FREE PROOF') ||
          text === 'CLICK HERE' ||
          text === 'START FREE PROOF') {
        // Skip if already has a data-form attribute pointing elsewhere
        if (hasDataAttr && hasDataAttr !== 'full-service') return;
        
        btn.addEventListener('click', openFullServiceModal);
      }
    });
  }
  
  // Register DIY buttons
  function registerDiyButtons() {
    document.querySelectorAll('[data-form="diy"]').forEach(btn => {
      btn.addEventListener('click', openDiyModal);
    });
  }
  
  function openFullServiceModal(e) {
    e.preventDefault();
    const modal = document.getElementById('fullServiceModal');
    if (modal) modal.style.display = 'flex';
  }
  
  function openDiyModal(e) {
    e.preventDefault();
    const modal = document.getElementById('diyModal');
    if (modal) modal.style.display = 'flex';
  }
  
  function closeModal(modal) {
    if (modal) modal.style.display = 'none';
  }
  
  // Register button handlers
  registerFullServiceButtons();
  registerDiyButtons();
  
  // Modal close buttons
  document.querySelectorAll('[data-close-modal]').forEach(btn => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      const modal = btn.closest('.form-modal');
      closeModal(modal);
    });
  });

  // Close modal on background click
  document.querySelectorAll('.form-modal').forEach(modal => {
    modal.addEventListener('click', (e) => {
      if (e.target === modal) closeModal(modal);
    });
  });

  // Form submissions
  const fullServiceForm = document.getElementById('fullServiceForm');
  if (fullServiceForm) {
    fullServiceForm.addEventListener('submit', submitFullServiceForm);
  }

  const diyForm = document.getElementById('diyForm');
  if (diyForm) {
    diyForm.addEventListener('submit', submitDiyForm);
  }
});
