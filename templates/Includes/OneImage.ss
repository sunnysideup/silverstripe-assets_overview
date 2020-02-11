<div class="one-image">
    <a href="$PathFromPublicRoot" target="_blank">
        <% if $IsImage %>
        <img src="$PathFromPublicRoot" height="250" alt="$DBTitle" />
    <% else %>
        <span>[$DBTitle]</span>
        <br />
        <span>[$Extension]</span>
    <% end_if %>

    </a>
    <a href="$CMSEditLink" class="edit-icon <% if $IsInDatabase %><% else %>error<% end_if %>" target="_blank">✎</a>
    <div class="one-image-info">
        <% if $IsInFileSystem %>
        <p><strong>Folder:</strong> <a href="$CMSEditLinkFolder" target="_blank">✎ $FolderNameFromAssetsFolder</a></p>
        <p><strong>File: </strong>$FileName . $Extension</p>
        <% else %>
            <p><a>Not in file-system</a></p>
        <% end_if %>
        <p><strong>Changed:</strong> $LastEdited</p>
        <p><strong>Dimensions:</strong> $HumanImageDimensions</p>
        <p><strong>Size:</strong> $HumanFileSize</p>
        <p><strong>DB:</strong> $HumanIsInDatabaseSummary</p>
    </div>
</div>
