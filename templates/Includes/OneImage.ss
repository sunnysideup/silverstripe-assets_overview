<div class="one-image">
    <a href="$PathFromPublicRoot" target="_blank" class="main-link">
    </a>
    <% if $IsImage %>
        <img src="$PathFromPublicRoot" height="250" alt="$DBTitle" />
    <% else %>
        <span class="main-title">[$DBTitle]</span>
        <br />
        <span class="sub-title">[$Extension]</span>
    <% end_if %>

    <% if $IsInDatabase %>
    <a href="$CMSEditLink" class="edit-icon" target="_blank">✎</a>
    <% end_if %>
    <div class="one-image-info">
        <% if $IsImage %>
            <p>
                <u>$DBTitle</u>
            </p>
        <% end_if %>
        <% if $IsInFileSystem %>
            <p><strong>Folder:</strong> <a href="$CMSEditLinkFolder" target="_blank">✎ $FolderNameFromAssetsFolder</a></p>
            <p><strong>File: </strong>$FileName . $Extension</p>
        <% else %>
            <p><a>Not in file-system</a></p>
        <% end_if %>
        <p><strong>Last Changed:</strong> $LastEdited</p>
        <p><strong>Dimensions:</strong> $HumanImageDimensions</p>
        <p><strong>Size:</strong> $HumanFileSize</p>
        <p><strong>DB:</strong> $HumanIsInDatabaseSummary</p>
    </div>
</div>
