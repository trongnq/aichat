<!-- BEGIN: main -->
<div class="panel panel-default">
  <div class="panel-heading">
    <h3 class="panel-title">⚙️ Cấu hình AI Chat</h3>
  </div>
  <div class="panel-body">

    <!-- BEGIN: saved_notice -->
    <div class="alert alert-success alert-dismissible">
      <button type="button" class="close" data-dismiss="alert">&times;</button>
      ✅ Đã lưu cấu hình thành công!
    </div>
    <!-- END: saved_notice -->

    <!-- ═══ TOGGLE BẬT/TẮT ═══ -->
    <div class="panel panel-primary">
      <div class="panel-heading"><strong>🔌 Trạng thái Widget AI Chat</strong></div>
      <div class="panel-body" style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
        <div id="nv-aichat-status-text" style="font-size:15px;font-weight:600;min-width:320px;"></div>
        <label id="nv-aichat-switch" style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;user-select:none;">
          <div id="nv-aichat-track" style="width:64px;height:34px;border-radius:17px;position:relative;transition:background .3s;flex-shrink:0;">
            <div id="nv-aichat-thumb" style="width:28px;height:28px;border-radius:50%;background:#fff;position:absolute;top:3px;transition:left .3s;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>
          </div>
          <span id="nv-aichat-label" style="font-size:16px;font-weight:700;min-width:40px;"></span>
        </label>
        <small class="text-muted">Widget chat nổi hiện/ẩn ngay lập tức trên toàn bộ trang web.</small>
      </div>
    </div>

    <form action="{FORM_ACTION}" method="post" class="form-horizontal">
      <input type="hidden" name="widget_enabled" id="nv-hidden-widget-enabled" value="{WIDGET_ENABLED}">

      <!-- ═══ CẤU HÌNH CHUNG ═══ -->
      <div class="panel panel-default">
        <div class="panel-heading"><strong>🌐 Cấu hình chung</strong></div>
        <div class="panel-body">
          <div class="form-group">
            <label class="col-sm-3 control-label">Nhà cung cấp mặc định</label>
            <div class="col-sm-4">
              <select name="active_provider" class="form-control">
                <!-- BEGIN: provider_option --><option value="{PROVIDER_VALUE}" {PROVIDER_SELECTED}>{PROVIDER_NAME}</option><!-- END: provider_option -->
              </select>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">Số token tối đa</label>
            <div class="col-sm-2">
              <input type="number" name="max_tokens" class="form-control" value="{DATA.max_tokens}" min="100" max="8000">
              <p class="help-block">100 – 8000</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">Temperature</label>
            <div class="col-sm-2">
              <input type="number" name="temperature" step="0.1" class="form-control" value="{DATA.temperature}" min="0" max="2">
              <p class="help-block">0.0 – 2.0</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">System Prompt</label>
            <div class="col-sm-9">
              <textarea name="system_prompt" rows="3" class="form-control">{DATA.system_prompt}</textarea>
              <p class="help-block">Hướng dẫn AI về vai trò và phong cách trả lời</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ TÌM KIẾM NỘI DUNG SITE ═══ -->
      <div class="panel panel-info">
        <div class="panel-heading"><strong>🔍 Tìm kiếm nội dung site để hỗ trợ AI trả lời</strong></div>
        <div class="panel-body">
          <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9">
              <div class="checkbox">
                <label style="font-weight:600;font-size:14px;">
                  <input type="checkbox" name="site_search_enabled" value="1" {SITE_SEARCH_CHECKED}>
                  Bật tìm kiếm nội dung site — AI sẽ trả lời dựa trên nội dung thực tế của website
                </label>
              </div>
              <p class="help-block">Khi bật, AI sẽ tìm bài viết liên quan trước khi trả lời, giúp câu trả lời chính xác hơn.</p>
            </div>
          </div>

          <div class="form-group">
            <label class="col-sm-3 control-label">Modules để tìm kiếm</label>
            <div class="col-sm-9">
              <div style="display:flex;flex-wrap:wrap;gap:8px;padding:12px;background:#f8f9fa;border:1px solid #dde;border-radius:6px;max-height:240px;overflow-y:auto;">
                <!-- BEGIN: search_module_item -->
                <label style="display:flex;align-items:center;gap:6px;padding:5px 12px;background:#fff;border:1px solid #dde;border-radius:20px;cursor:pointer;margin:0;font-weight:normal;white-space:nowrap;transition:all .15s;">
                  <input type="checkbox" name="search_modules_check[]" value="{MOD_KEY}" {MOD_CHECKED} style="margin:0;">
                  <span>{MOD_LABEL}</span>
                  <small class="text-muted">({MOD_KEY})</small>
                </label>
                <!-- END: search_module_item -->
              </div>
              <p class="help-block">Chọn các module mà AI được phép tìm kiếm nội dung để đưa vào context.</p>
            </div>
          </div>

          <div class="form-group">
            <label class="col-sm-3 control-label">Số kết quả tìm kiếm</label>
            <div class="col-sm-2">
              <input type="number" name="search_max_results" class="form-control" value="{SEARCH_MAX_RESULTS}" min="1" max="20">
              <p class="help-block">Tối đa 20</p>
            </div>
          </div>

          <div class="form-group">
            <label class="col-sm-3 control-label">Gợi ý câu hỏi</label>
            <div class="col-sm-9">
              <textarea name="search_suggest_text" rows="4" class="form-control" placeholder="Mỗi gợi ý một dòng&#10;VD:&#10;Tìm kiếm tin tức mới nhất&#10;Sản phẩm nổi bật">{DATA.search_suggest_text}</textarea>
              <p class="help-block">Mỗi dòng là một gợi ý câu hỏi hiện ngay trong widget (tối đa 4 gợi ý đầu).</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ OPENAI ═══ -->
      <div class="panel panel-success">
        <div class="panel-heading"><strong>🤖 OpenAI (ChatGPT)</strong></div>
        <div class="panel-body">
          <div class="form-group">
            <label class="col-sm-3 control-label">API Key <small class="text-muted">{OPENAI_KEY_SET}</small></label>
            <div class="col-sm-8">
              <input type="password" name="openai_api_key" class="form-control" placeholder="sk-..." autocomplete="new-password">
              <p class="help-block"><a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">Lấy key tại OpenAI Platform ↗</a></p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">Model</label>
            <div class="col-sm-5">
              <select name="openai_model" class="form-control">
                <option value="gpt-4o"        {SELECTED.openai_gpt_4o}>GPT-4o</option>
                <option value="gpt-4o-mini"   {SELECTED.openai_gpt_4o_mini}>GPT-4o Mini (khuyến nghị)</option>
                <option value="gpt-4-turbo"   {SELECTED.openai_gpt_4_turbo}>GPT-4 Turbo</option>
                <option value="gpt-4"         {SELECTED.openai_gpt_4}>GPT-4</option>
                <option value="gpt-3.5-turbo" {SELECTED.openai_gpt_3_5_turbo}>GPT-3.5 Turbo</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ GEMINI ═══ -->
      <div class="panel panel-warning">
        <div class="panel-heading"><strong>✨ Google Gemini</strong></div>
        <div class="panel-body">
          <div class="form-group">
            <label class="col-sm-3 control-label">API Key <small class="text-muted">{GEMINI_KEY_SET}</small></label>
            <div class="col-sm-8">
              <input type="password" name="gemini_api_key" class="form-control" placeholder="AIza..." autocomplete="new-password">
              <p class="help-block"><a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Lấy key tại Google AI Studio ↗</a></p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">Model</label>
            <div class="col-sm-5">
              <select name="gemini_model" class="form-control">
                <option value="gemini-2.5-flash-lite" {SELECTED.gemini_gemini_2_5_flash_lite}>Gemini 2.5 Flash Lite (khuyến nghị)</option>
                <option value="gemini-2.5-flash"      {SELECTED.gemini_gemini_2_5_flash}>Gemini 2.5 Flash</option>
                <option value="gemini-2.0-flash"      {SELECTED.gemini_gemini_2_0_flash}>Gemini 2.0 Flash (cũ)</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ DEEPSEEK ═══ -->
      <div class="panel panel-danger">
        <div class="panel-heading"><strong>🔮 DeepSeek</strong></div>
        <div class="panel-body">
          <div class="form-group">
            <label class="col-sm-3 control-label">API Key <small class="text-muted">{DEEPSEEK_KEY_SET}</small></label>
            <div class="col-sm-8">
              <input type="password" name="deepseek_api_key" class="form-control" placeholder="sk-..." autocomplete="new-password">
              <p class="help-block"><a href="https://platform.deepseek.com/api_keys" target="_blank" rel="noopener">Lấy key tại DeepSeek Platform ↗</a></p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">Model</label>
            <div class="col-sm-5">
              <select name="deepseek_model" class="form-control">
                <option value="deepseek-chat"     {SELECTED.deepseek_deepseek_chat}>DeepSeek Chat</option>
                <option value="deepseek-reasoner" {SELECTED.deepseek_deepseek_reasoner}>DeepSeek Reasoner</option>
                <option value="deepseek-coder"    {SELECTED.deepseek_deepseek_coder}>DeepSeek Coder</option>
              </select>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══ LOCAL AI ═══ -->
      <div class="panel panel-default">
        <div class="panel-heading"><strong>🖥️ AI Local (Ollama)</strong></div>
        <div class="panel-body">
          <div class="form-group">
            <label class="col-sm-3 control-label">URL API</label>
            <div class="col-sm-7">
              <input type="text" name="local_ai_url" class="form-control" value="{DATA.local_ai_url}">
              <p class="help-block">VD: http://localhost:11434/api/generate</p>
            </div>
          </div>
          <div class="form-group">
            <label class="col-sm-3 control-label">Model</label>
            <div class="col-sm-4">
              <input type="text" name="local_ai_model" class="form-control" value="{DATA.local_ai_model}">
              <p class="help-block">VD: llama3, phi3, mistral, qwen2</p>
            </div>
          </div>
        </div>
      </div>

      <div class="form-group" style="margin-top:10px;">
        <div class="col-sm-offset-3 col-sm-9">
          <button type="submit" name="submit" class="btn btn-primary btn-lg">
            💾 Lưu cấu hình
          </button>
        </div>
      </div>

    </form>
  </div>
</div>

<style>
  #nv-aichat-switch label:hover { background: #f0f4ff; }
  .search_module_item_label:hover { border-color: #667eea !important; background: #f0f4ff !important; }
</style>

<script>
(function(){
  'use strict';
  var enabled = parseInt('{WIDGET_ENABLED}', 10) === 1;
  var TOGGLE_URL = '{TOGGLE_URL}';
  var track  = document.getElementById('nv-aichat-track');
  var thumb  = document.getElementById('nv-aichat-thumb');
  var label  = document.getElementById('nv-aichat-label');
  var status = document.getElementById('nv-aichat-status-text');
  var hidden = document.getElementById('nv-hidden-widget-enabled');

  function applyUI(on) {
    track.style.background  = on ? '#28a745' : '#ccc';
    thumb.style.left        = on ? '33px' : '3px';
    label.textContent       = on ? 'BẬT' : 'TẮT';
    label.style.color       = on ? '#28a745' : '#999';
    status.innerHTML        = on
      ? '🟢 <span style="color:#28a745;font-weight:600;">Widget đang HIỂN THỊ trên tất cả các trang</span>'
      : '🔴 <span style="color:#999;">Widget đang bị ẨN</span>';
    if (hidden) hidden.value = on ? '1' : '0';
  }

  applyUI(enabled);

  document.getElementById('nv-aichat-switch').addEventListener('click', function(e) {
    e.preventDefault();
    enabled = !enabled;
    applyUI(enabled);
    var fd = new FormData();
    fd.append('toggle_widget', enabled ? '1' : '0');
    fetch(TOGGLE_URL, { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (!d.ok) { enabled = !enabled; applyUI(enabled); alert('Lỗi khi lưu trạng thái.'); }
      })
      .catch(function() { enabled = !enabled; applyUI(enabled); alert('Lỗi kết nối.'); });
  });

  // Style checkbox labels đẹp hơn khi hover
  document.querySelectorAll('[name="search_modules_check[]"]').forEach(function(cb) {
    var lbl = cb.closest('label');
    if (!lbl) return;
    cb.addEventListener('change', function() {
      lbl.style.borderColor = cb.checked ? '#667eea' : '';
      lbl.style.background  = cb.checked ? '#f0f4ff' : '';
    });
    if (cb.checked) {
      lbl.style.borderColor = '#667eea';
      lbl.style.background  = '#f0f4ff';
    }
  });
}());
</script>
<!-- END: main -->
