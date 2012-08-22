<div class="extra-info-list">
{{#list}}
    <div class="extra-info">
        <dl>
            {{#each}}
            <dt>{{key}}</dt>
            <dd><pre>{{value}}</pre></dd>
            {{/each}}
        </dl>
    </div>
{{/list}}
</div>
