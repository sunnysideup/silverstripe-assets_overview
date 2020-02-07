<div class="one-image">
    <% if $IsImage %>
    <a href="$PathFromRoot" target="_blank">
        <img src="$PathFromRoot" height="150" alt="PathFromRoot" />
    </a>
    <% end_if %>
    <a href="$CMSEditLink" class="edit-icon <% if $IsInDatabase %><% else %>error<% end_if %>" target="_blank">✎</a>
    <div class="one-image-info">
    <u><% if $IsInDatabase %>$DBTitle<% else %>not in database!<% end_if %></u>
        <% if $IsInFileSystem %>
        <a href="$CMSEditLinkFolder" target="_blank">✎ $FolderNameShort</a> <strong>$FileName</strong> . $Extension<br />
        <% else %>
            <a>Not in file-system</a>
        <% end_if %>
        $LastEdited<br />
        $HumanImageDimensions<br />
        $HumanFileSize
    </div>
</div>
